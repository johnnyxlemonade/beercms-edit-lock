<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Unit;

use BeersCms\EditLock\Dto\EditLockDto;
use BeersCms\EditLock\Dto\EditLockResultDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regresni testy kontraktu DTO.
 */
final class EditLockDtoTest extends TestCase
{
    /**
     * Overi, ze withHeartbeat vytvori novou instanci a puvodni zachova.
     */
    public function testWithHeartbeatDoesNotMutateOriginal(): void
    {
        $original = new EditLockDto('article', 'X', 'A', 'Alice', 1000, 1000, 'token');
        $updated = $original->withHeartbeat(1100);
        self::assertNotSame($original, $updated);
        self::assertSame(1000, $original->getHeartbeatAt());
        self::assertSame(1100, $updated->getHeartbeatAt());
        self::assertSame($original->getToken(), $updated->getToken());
        self::assertSame($original->getAcquiredAt(), $updated->getAcquiredAt());
        self::assertSame($original->getResourceType(), $updated->getResourceType());
        self::assertSame($original->getResourceId(), $updated->getResourceId());
    }

    /**
     * Overi bezeztratovy prevod DTO pres serializovanou strukturu.
     */
    public function testSerializationRoundTrip(): void
    {
        $data = ['resource_type' => 'page', 'resource_id' => '42', 'user' => 'A',
            'name' => 'Alice', 'acquired_at' => 1000, 'heartbeat_at' => 1100, 'token' => 'token'];
        $lock = EditLockDto::fromArray($data);
        self::assertSame($data, $lock->toArray());
        self::assertEquals($lock, EditLockDto::fromArray($lock->toArray()));
    }

    /**
     * Overi nemennost DTO pomoci private readonly properties v PHP 8.1.
     */
    public function testAllDtoPropertiesArePrivateReadonlyOnPhp81(): void
    {
        foreach ([EditLockDto::class, EditLockResultDto::class] as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal());
            foreach ($reflection->getProperties() as $property) {
                self::assertTrue($property->isPrivate());
                self::assertTrue($property->isReadOnly());
            }
        }
    }
}
