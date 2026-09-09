<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Unit;

use BeersCms\EditLock\Command\EditLockCommand;
use BeersCms\EditLock\Command\EditLockCommandHandler;
use BeersCms\EditLock\Command\EditLockOperation;
use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Exception\LockNotOwnedException;
use BeersCms\EditLock\Tests\Support\InMemoryStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Overi delegaci prikazu a zachovani kontraktu vlastnictvi. */
final class EditLockCommandHandlerTest extends TestCase
{
    /** Heartbeat prodlouzi spravny resource a release odstrani jen jeho zaznam. */
    public function testRefreshAndReleasePreserveResourceIdentity(): void
    {
        $storage = new InMemoryStorage();
        $initial = new EditLockService($storage, 900, 1000);
        $lock = $initial->acquire('page', '42', 'A')->getLock();
        self::assertNotNull($lock);
        $other = $initial->acquire('article', '42', 'B')->getLock();
        self::assertNotNull($other);
        $initial->close();
        $service = new EditLockService($storage, 900, 1100);
        $handler = new EditLockCommandHandler($service);
        try {
            $result = $handler->handle(new EditLockCommand(EditLockOperation::Refresh, 'page', '42', 'A', $lock->getToken()));
            self::assertTrue($result->isSuccess());
            $updated = $result->getLock();
            self::assertNotNull($updated);
            self::assertSame(1100, $updated->getHeartbeatAt());
            self::assertSame($lock->getToken(), $updated->getToken());
            $released = $handler->handle(new EditLockCommand(EditLockOperation::Release, 'page', '42', 'A', $lock->getToken()));
            self::assertTrue($released->isSuccess());
            self::assertNull($storage->find('page', '42'));
            self::assertSame($other, $storage->find('article', '42'));
        } finally {
            $service->close();
        }
    }

    /** Ani znalost ciziho tokenu nebo identity samotne neopravnuje k operaci. */
    public function testCommandsRejectForeignUserAndWrongToken(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $lock = $service->acquire('page', '42', 'A')->getLock();
        self::assertNotNull($lock);
        $handler = new EditLockCommandHandler($service);
        try {
            foreach (EditLockOperation::cases() as $operation) {
                foreach ([['B', $lock->getToken()], ['A', 'wrong']] as [$user, $token]) {
                    $result = $handler->handle(new EditLockCommand($operation, 'page', '42', $user, $token));
                    self::assertFalse($result->isSuccess());
                    self::assertInstanceOf(LockNotOwnedException::class, $result->getError());
                    self::assertSame($lock, $storage->find('page', '42'));
                }
            }
        } finally {
            $service->close();
        }
    }

    /** Handler nesmi obnovit ani uvolnit zaznam expirovany na hranici TTL. */
    public function testExpiredCommandsAreRejected(): void
    {
        $storage = new InMemoryStorage();
        $initial = new EditLockService($storage, 900, 1000);
        $lock = $initial->acquire('page', '42', 'A')->getLock();
        self::assertNotNull($lock);
        $initial->close();
        $service = new EditLockService($storage, 900, 1900);
        try {
            foreach (EditLockOperation::cases() as $operation) {
                $result = (new EditLockCommandHandler($service))->handle(new EditLockCommand($operation, 'page', '42', 'A', $lock->getToken()));
                self::assertFalse($result->isSuccess());
                self::assertInstanceOf(LockNotOwnedException::class, $result->getError());
            }
        } finally {
            $service->close();
        }
    }

    /** Command je nemenny a uchovava typovanou operaci. */
    public function testImmutableCommandAndTypedOperation(): void
    {
        $command = new EditLockCommand(EditLockOperation::Refresh, 'page', '42', 'A', 'token');
        self::assertSame(EditLockOperation::Refresh, $command->getOperation());
        self::assertSame('page', $command->getResourceType());
        self::assertSame('42', $command->getResourceId());
        self::assertSame('A', $command->getUser());
        self::assertSame('token', $command->getToken());
        $reflection = new ReflectionClass($command);
        self::assertTrue($reflection->isFinal());
        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isPrivate());
            self::assertTrue($property->isReadOnly());
        }
    }

    /** Resource type nema implicitni vychozi hodnotu. */
    public function testCommandRejectsEmptyResourceType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EditLockCommand(EditLockOperation::Refresh, '', '42', 'A', 'token');
    }
}
