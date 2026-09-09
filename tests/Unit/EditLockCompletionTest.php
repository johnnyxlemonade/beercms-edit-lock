<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Unit;

use BeersCms\EditLock\Dto\EditLockDto;
use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Exception\LockConflictException;
use BeersCms\EditLock\Exception\LockNotOwnedException;
use BeersCms\EditLock\Tests\Support\InMemoryStorage;
use BeersCms\EditLock\ValueObject\EditLockResource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Overuje obecny lifecycle docasneho resource a dokonceni zapisu. */
final class EditLockCompletionTest extends TestCase
{
    /** Docasna ID jsou nezavisla, nemenna a obnovitelna pouze ve stejnem prostoru. */
    public function testTemporaryResources(): void
    {
        $resource = EditLockResource::temporary('page', 'space/');
        self::assertSame('page', $resource->getType());
        self::assertMatchesRegularExpression('/^space\/new:[a-f0-9]{32}$/', $resource->getId());
        self::assertNotSame($resource->getId(), EditLockResource::temporary('page', 'space/')->getId());
        self::assertEquals($resource, EditLockResource::restoreTemporary('page', 'space/', $resource->getId()));
        self::assertNull(EditLockResource::restoreTemporary('page', 'other/', $resource->getId()));
        self::assertNull(EditLockResource::restoreTemporary('page', 'space/', $resource->getId() . '/extra'));
        $reflection = new ReflectionClass($resource);
        self::assertTrue($reflection->isFinal());
        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isPrivate());
            self::assertTrue($property->isReadOnly());
        }
    }

    /** Prubezny save prevadi identitu i typ bez znalosti konkretni aplikace. */
    public function testTransferAndFinalRelease(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $source = EditLockResource::temporary('draft', 'workspace/');
        $lock = $service->acquire($source->getType(), $source->getId(), 'A', 'Alice')->requireLock();
        $continued = $service->complete($lock, true, new EditLockResource('page', '42'));
        self::assertNotNull($continued);
        self::assertSame('page', $continued->getResourceType());
        self::assertSame('42', $continued->getResourceId());
        self::assertSame('Alice', $continued->getName());
        self::assertNotSame($lock->getToken(), $continued->getToken());
        $next = new EditLockService($storage, 900, 1100);
        self::assertFalse($next->assertOwned('draft', $source->getId(), 'A', $lock->getToken())->isSuccess());
        self::assertTrue($next->assertOwned('page', '42', 'A', $continued->getToken())->isSuccess());
        self::assertNull($next->complete($continued));
        $last = new EditLockService($storage, 900, 1100);
        self::assertTrue($last->acquire('page', '42', 'B')->isSuccess());
        $last->close();
    }

    /** Zachovani stejne identity obnovi heartbeat bez vymeny tokenu. */
    public function testContinueSameResource(): void
    {
        $storage = new InMemoryStorage();
        $first = new EditLockService($storage, 900, 1000);
        $lock = $first->acquire('page', '42', 'A')->requireLock();
        $first->close();
        $next = new EditLockService($storage, 900, 1100);
        $continued = $next->complete($lock, true, new EditLockResource('page', '42'));
        self::assertNotNull($continued);
        self::assertSame($lock->getToken(), $continued->getToken());
        $check = new EditLockService($storage, 900, 1100);
        self::assertSame(1100, $check->assertOwned('page', '42', 'A', $lock->getToken())->requireLock()->getHeartbeatAt());
        $check->close();
    }

    /** Konflikt ciloveho resource nesmi uvolnit puvodni zamek. */
    public function testTargetConflictPreservesSource(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $lock = $service->acquire('page', 'draft', 'A')->requireLock();
        $other = $service->acquire('page', '42', 'B')->requireLock();
        try {
            $service->complete($lock, true, new EditLockResource('page', '42'));
            self::fail('Ocekavan konflikt ciloveho resource.');
        } catch (LockConflictException $error) {
            self::assertSame('B', $error->getOwnerUser());
            self::assertSame($lock, $service->assertOwned('page', 'draft', 'A', $lock->getToken())->requireLock());
            self::assertSame($other, $service->assertOwned('page', '42', 'B', $other->getToken())->requireLock());
        } finally {
            $service->close();
        }
    }

    /** Cizi vlastnik nesmi dokoncenim zapisu presunout ani odstranit zamek. */
    public function testCompletionRejectsForeignOwner(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $lock = $service->acquire('page', '42', 'A')->requireLock();
        $foreign = new EditLockDto('page', '42', 'B', 'Bob', 1000, 1000, $lock->getToken());
        try {
            $this->expectException(LockNotOwnedException::class);
            $service->complete($foreign, true, new EditLockResource('page', '43'));
        } finally {
            self::assertSame($lock, $storage->find('page', '42'));
            self::assertNull($storage->find('page', '43'));
            $service->close();
        }
    }
}
