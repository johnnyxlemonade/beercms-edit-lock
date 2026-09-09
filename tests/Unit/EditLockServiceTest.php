<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Unit;

use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Exception\LockConflictException;
use BeersCms\EditLock\Exception\LockNotOwnedException;
use BeersCms\EditLock\Tests\Support\InMemoryStorage;
use PHPUnit\Framework\TestCase;

/**
 * Regresni testy kontraktu sluzby.
 */
final class EditLockServiceTest extends TestCase
{
    /**
     * Overi nove acquire a odmitnuti druheho vlastnika.
     */
    public function testAcquireNewLockAndConflict(): void
    {
        $service = new EditLockService(new InMemoryStorage(), 900, 1000);
        $result = $service->acquire('article', 'X', 'A', 'Alice');
        self::assertTrue($result->isSuccess());
        $lock = $result->getLock();
        self::assertNotNull($lock);
        self::assertSame('A', $lock->getUser());
        self::assertSame('Alice', $lock->getName());
        self::assertSame(1000, $lock->getAcquiredAt());
        self::assertSame(1000, $lock->getHeartbeatAt());
        self::assertSame(64, strlen($lock->getToken()));
        $conflict = $service->acquire('article', 'X', 'B');
        self::assertFalse($conflict->isSuccess());
        self::assertNull($conflict->getLock());
        self::assertInstanceOf(LockConflictException::class, $conflict->getError());
        self::assertStringContainsString('Alice', $conflict->getMessage());
    }

    /**
     * Overi obnoveni vlastnikem a odmitnuti ciziho uzivatele.
     */
    public function testRefreshOwnerAndRejectForeignUser(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $lock = $service->acquire('article', 'X', 'A')->getLock();
        self::assertNotNull($lock);
        $service->close();
        $service = new EditLockService($storage, 900, 1100);
        $refreshed = $service->refresh('article', 'X', 'A', $lock->getToken());
        self::assertTrue($refreshed->isSuccess());
        $updated = $refreshed->getLock();
        self::assertNotNull($updated);
        self::assertSame(1100, $updated->getHeartbeatAt());
        self::assertSame(1000, $updated->getAcquiredAt());
        self::assertSame($lock->getToken(), $updated->getToken());
        self::assertFalse($service->refresh('article', 'X', 'B', $lock->getToken())->isSuccess());
        self::assertFalse($service->refresh('article', 'X', 'A', 'wrong-token')->isSuccess());
    }

    /**
     * Overi uvolneni vlastnikem bez moznosti ciziho release.
     */
    public function testReleaseOwnerAndRejectForeignUser(): void
    {
        $service = new EditLockService(new InMemoryStorage(), 900, 1000);
        $lock = $service->acquire('article', 'X', 'A')->getLock();
        self::assertNotNull($lock);
        $foreign = $service->release('article', 'X', 'B', $lock->getToken());
        self::assertFalse($foreign->isSuccess());
        self::assertInstanceOf(LockNotOwnedException::class, $foreign->getError());
        self::assertTrue($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertTrue($service->release('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertFalse($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertTrue($service->acquire('article', 'X', 'B')->isSuccess());
        self::assertFalse($service->release('article', 'X', 'A', $lock->getToken())->isSuccess());
    }

    /**
     * Overi, ze bez zaznamu nebo spravneho tokenu vlastnictvi neplati.
     */
    public function testAssertOwnedRejectsMissingLockAndWrongToken(): void
    {
        $service = new EditLockService(new InMemoryStorage(), 900, 1000);
        self::assertFalse($service->assertOwned('article', 'X', 'A', 'missing')->isSuccess());
        $lock = $service->acquire('article', 'X', 'A')->getLock();
        self::assertNotNull($lock);
        self::assertFalse($service->assertOwned('article', 'X', 'A', 'wrong')->isSuccess());
        self::assertTrue($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertTrue($service->acquire('article', 'X', 'A', null, $lock->getToken())->isSuccess());
    }

    /**
     * Overi expiraci presne na hranici TTL.
     */
    public function testExpirationAtTtlBoundary(): void
    {
        $storage = new InMemoryStorage();
        $service = new EditLockService($storage, 900, 1000);
        $lock = $service->acquire('article', 'X', 'A')->getLock();
        self::assertNotNull($lock);
        $service->close();
        $service = new EditLockService($storage, 900, 1899);
        self::assertTrue($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        $service->close();
        $service = new EditLockService($storage, 900, 1900);
        self::assertFalse($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertTrue($service->acquire('article', 'X', 'B')->isSuccess());
    }

    /**
     * Overi nezavislost shodneho ID v ruznych typech resource.
     */
    public function testSameIdAcrossResourceTypesDoesNotCollide(): void
    {
        $service = new EditLockService(new InMemoryStorage());
        self::assertTrue($service->acquire('article', '42', 'A')->isSuccess());
        self::assertTrue($service->acquire('page', '42', 'B')->isSuccess());
        self::assertTrue($service->acquire('event', '42', 'C')->isSuccess());
        self::assertFalse($service->acquire('page', '42', 'A')->isSuccess());
    }
}
