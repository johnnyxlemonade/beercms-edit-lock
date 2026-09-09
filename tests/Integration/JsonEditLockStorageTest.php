<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Integration;

use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Exception\EditLockException;
use BeersCms\EditLock\Storage\JsonEditLockStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regresni testy kontraktu JSON uloziste a soubeznych procesu.
 */
final class JsonEditLockStorageTest extends TestCase
{
    private string $directory;
    private string $file;

    /**
     * Vytvori izolovane uloziste pro kazdy integracni scenar.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/edit-lock-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create test directory.');
        }
        $this->file = $this->directory . '/edit-locks.json';
    }

    /**
     * Odstrani pouze soubory izolovaneho testovaciho uloziste.
     */
    protected function tearDown(): void
    {
        $files = scandir($this->directory);
        if ($files === false) {
            throw new RuntimeException('Cannot list test directory.');
        }
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }
        rmdir($this->directory);
    }

    /**
     * Overi persistenci mezi transakcemi a uklid expirovanych zaznamu.
     */
    public function testPersistenceRoundTripAndExpiryCleanup(): void
    {
        $service = new EditLockService(new JsonEditLockStorage($this->file), 900, 1000);
        $lock = $service->acquire('article', 'X', 'A')->getLock();
        self::assertNotNull($lock);
        $service->close();
        $service = new EditLockService(new JsonEditLockStorage($this->file), 900, 1899);
        self::assertTrue($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        $service->close();
        $service = new EditLockService(new JsonEditLockStorage($this->file), 900, 1900);
        self::assertFalse($service->assertOwned('article', 'X', 'A', $lock->getToken())->isSuccess());
        self::assertTrue($service->acquire('page', 'X', 'B')->isSuccess());
        $service->close();
        $json = file_get_contents($this->file);
        self::assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertArrayHasKey(hash('sha256', '4:pageX'), $decoded);
        self::assertSame([], glob($this->directory . '/.edit-lock-*'));
    }

    /** Drive kolidujici dvojice zustanou nezavisle i po opetovnem nacteni JSON. */
    public function testBinaryResourceIdentitiesHaveDistinctKeys(): void
    {
        $service = new EditLockService(new JsonEditLockStorage($this->file));
        $first = $service->acquire("a\0b", 'c', 'A')->requireLock();
        $second = $service->acquire('a', "b\0c", 'B')->requireLock();
        $service->close();
        $service = new EditLockService(new JsonEditLockStorage($this->file));
        self::assertTrue($service->assertOwned("a\0b", 'c', 'A', $first->getToken())->isSuccess());
        self::assertTrue($service->assertOwned('a', "b\0c", 'B', $second->getToken())->isSuccess());
        $service->close();
        $json = file_get_contents($this->file);
        self::assertIsString($json);
        $records = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($records);
        self::assertCount(2, $records);
        self::assertArrayHasKey(hash('sha256', "3:a\0bc"), $records);
        self::assertArrayHasKey(hash('sha256', "1:ab\0c"), $records);
    }

    /** Aktualizace formatu nesmi zneplatnit aktivni tokeny z predchozi verze. */
    public function testPreviousKeysAreReindexedWithoutLosingOwnership(): void
    {
        $service = new EditLockService(new JsonEditLockStorage($this->file));
        $lock = $service->acquire('article', 'X', 'A')->requireLock();
        $service->close();
        file_put_contents($this->file, json_encode([hash('sha256', "article\0X") => $lock->toArray()], JSON_THROW_ON_ERROR));
        $service = new EditLockService(new JsonEditLockStorage($this->file));
        self::assertTrue($service->refresh('article', 'X', 'A', $lock->getToken())->isSuccess());
        $service->close();
        $json = file_get_contents($this->file);
        self::assertIsString($json);
        $records = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($records);
        self::assertArrayHasKey(hash('sha256', '7:articleX'), $records);
        self::assertArrayNotHasKey(hash('sha256', "article\0X"), $records);
    }

    /** Overi, ze poskozene uloziste neni povazovano za prazdne ani prepsano. */
    public function testCorruptJsonFailsClosedAndIsNotOverwritten(): void
    {
        file_put_contents($this->file, '{broken');
        try {
            new EditLockService(new JsonEditLockStorage($this->file));
            self::fail('Corrupt storage must fail closed.');
        } catch (EditLockException $error) {
            self::assertStringContainsString('JSON', $error->getMessage());
        }
        self::assertSame('{broken', file_get_contents($this->file));
    }

    /**
     * Overi jedineho viteze soubezneho acquire stejneho resource.
     */
    public function testParallelAcquireHasExactlyOneWinner(): void
    {
        $outcomes = $this->race(true);
        self::assertCount(1, array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'won'));
        self::assertCount(11, array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'lost'));
        $this->assertValidStorageWithSentinel(2);
    }

    /**
     * Overi, ze soubezne zapisy ruznych resource neztrati zaznamy.
     */
    public function testParallelDifferentResourcesDoNotLoseRecords(): void
    {
        self::assertSame(array_fill(0, 12, 'won'), $this->race(false));
        $this->assertValidStorageWithSentinel(13);
    }

    /**
     * Spusti procesy se spolecnou startovni barierou nad jednim ulozistem.
     * Kazdy proces pouziva vlastni sluzbu i handle mutexu, aby test overil flock.
     *
     * @return list<string> Vysledky acquire jednotlivych procesu.
     */
    private function race(bool $sameResource): array
    {
        $service = new EditLockService(new JsonEditLockStorage($this->file));
        self::assertTrue($service->acquire('existing', 'sentinel', 'original')->isSuccess());
        $service->close();
        $gate = $this->directory . '/start';
        /** @var list<array{process: resource, stdout: resource, stderr: resource}> $workers */
        $workers = [];
        $outcomes = [];
        try {
            for ($i = 0; $i < 12; $i++) {
                $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . dirname(__DIR__, 2) . '/vendor/autoload.php',
                    __DIR__ . '/acquire-worker.php', $this->file, $gate, 'user-' . $i, $sameResource ? 'X' : 'item-' . $i];
                $pipes = [];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if ($process === false || !isset($pipes[0], $pipes[1], $pipes[2])) {
                    throw new RuntimeException('Cannot launch worker.');
                }
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }
            file_put_contents($gate, 'start');
            while (($worker = array_pop($workers)) !== null) {
                $outcome = stream_get_contents($worker['stdout']);
                $errors = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exit = proc_close($worker['process']);
                self::assertSame('', $errors);
                self::assertSame(0, $exit);
                self::assertIsString($outcome);
                $outcomes[] = $outcome;
            }
        } finally {
            foreach ($workers as $worker) {
                proc_terminate($worker['process']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                proc_close($worker['process']);
            }
        }
        return $outcomes;
    }

    /**
     * Overi validitu JSON i zachovani zaznamu vytvoreneho pred soubehem.
     */
    private function assertValidStorageWithSentinel(int $count): void
    {
        $json = file_get_contents($this->file);
        self::assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount($count, $decoded);
        $storage = new JsonEditLockStorage($this->file);
        $storage->open();
        $sentinel = $storage->find('existing', 'sentinel');
        self::assertNotNull($sentinel);
        self::assertSame('original', $sentinel->getUser());
        $storage->close();
    }
}
