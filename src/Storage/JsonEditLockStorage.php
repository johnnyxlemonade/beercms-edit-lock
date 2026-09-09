<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Storage;

use BeersCms\EditLock\Dto\EditLockDto;
use BeersCms\EditLock\Exception\EditLockException;
use JsonException;
use Throwable;

/**
 * JSON implementace uloziste editacnich zamku.
 *
 * Zajistuje synchronizovany read-modify-write cyklus pomoci flock.
 * Trvaly soubor .lock se nikdy nenahrazuje; vsechna cteni i zapisy
 * sdileji jeho mutex. JSON snimek se naopak nahrazuje atomickym rename.
 * Transakce zustava otevrena i behem chraneneho aplikacniho zapisu.
 */
final class JsonEditLockStorage implements EditLockStorageInterface
{
    /** @var resource|null PHP nema nativni typ property pro otevreny soubor. */
    private $mutex = null;
    /** @var array<string, EditLockDto> */
    private array $locks = [];

    /**
     * Nastavi cestu JSON uloziste; transakci otevre az open.
     *
     * @param string $file Cesta v existujicim zapisovatelnem adresari mimo verejny web.
     */
    public function __construct(private readonly string $file) {}

    /**
     * Otevre exkluzivni transakci; soubezne operace nad stejnym ulozistem musi cekat.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe otevreni nebo nacteni.
     */
    public function open(): void
    {
        if ($this->mutex !== null) {
            throw new EditLockException('Storage transaction is already open.');
        }
        $mutex = fopen($this->file . '.lock', 'c+');
        if ($mutex === false) {
            throw new EditLockException('Cannot open the edit-lock mutex.');
        }
        if (!flock($mutex, LOCK_EX)) {
            fclose($mutex);
            throw new EditLockException('Cannot acquire the edit-lock mutex.');
        }
        $this->mutex = $mutex;
        try {
            $this->locks = [];
            if (is_file($this->file)) {
                $json = file_get_contents($this->file);
                if ($json === false) {
                    throw new EditLockException('Cannot read edit-lock storage.');
                }
                $this->locks = $this->decode($json);
            }
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /**
     * Vrati ulozeny snimek bez posouzeni vlastnictvi nebo TTL.
     *
     * @return EditLockDto|null Null pokud dvojice typu a ID nema zaznam.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pokud transakce neni otevrena.
     */
    public function find(string $resourceType, string $resourceId): ?EditLockDto
    {
        $this->assertOpen();
        return $this->locks[$this->key($resourceType, $resourceId)] ?? null;
    }

    /**
     * Ulozi nebo nahradi snimek pod jeho typem a ID v ramci transakce.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zaznam nelze serializovat.
     */
    public function put(EditLockDto $lock): void
    {
        $this->assertOpen();
        $next = $this->locks;
        $next[$this->key($lock->getResourceType(), $lock->getResourceId())] = $lock;
        $this->persist($next);
    }

    /**
     * Odstrani zaznam daneho typu a ID; neexistujici zaznam neni chyba vlastnictvi.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zbyvajici zaznamy nelze serializovat.
     */
    public function remove(string $resourceType, string $resourceId): void
    {
        $this->assertOpen();
        $next = $this->locks;
        unset($next[$this->key($resourceType, $resourceId)]);
        $this->persist($next);
    }

    /**
     * Odstrani zaznamy s heartbeat mensim nebo rovnym dodane hranici.
     *
     * @param int $heartbeatCutoff Unix timestamp v sekundach vypocteny sluzbou jako cas minus TTL.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zbyvajici zaznamy nelze serializovat.
     */
    public function removeExpired(int $heartbeatCutoff): void
    {
        $this->assertOpen();
        $next = array_filter($this->locks, static fn(EditLockDto $lock): bool => $lock->getHeartbeatAt() > $heartbeatCutoff);
        if (count($next) !== count($this->locks)) {
            $this->persist($next);
        }
    }

    /**
     * Chrani pristup k lokalnimu snimku pred pouzitim mimo exkluzivni transakci.
     *
     * @throws EditLockException Pokud mutex neni drzen.
     */
    private function assertOpen(): void
    {
        if ($this->mutex === null) {
            throw new EditLockException('Storage transaction is not open.');
        }
    }

    /**
     * Delka typu jednoznacne oddeli oba retezce i pri vlozenych nulovych bajtech.
     * Aplikace musi dodavat kanonicke identity; metoda neprovadi normalizaci cest.
     */
    private function key(string $type, string $id): string
    {
        return hash('sha256', strlen($type) . ':' . $type . $id);
    }

    /**
     * Jedina nevyhnutelna hranice mixed je vystup json_decode.
     * Kazde pole neduveryhodneho JSON se overi pred vytvorenim DTO.
     * Chybny zaznam nebo neshoda klice zastavi nacteni misto prepsani dat.
     *
     * @throws EditLockException Pri neplatnem JSON, strukture nebo klici.
     * @return array<string, EditLockDto>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new EditLockException('Invalid edit-lock JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new EditLockException('Edit-lock JSON must contain an object.');
        }
        $locks = [];
        foreach ($decoded as $key => $entry) {
            if (!is_string($key) || !is_array($entry)
                || !is_string($entry['resource_type'] ?? null) || !is_string($entry['resource_id'] ?? null)
                || !is_string($entry['user'] ?? null) || !is_string($entry['name'] ?? null)
                || !is_int($entry['acquired_at'] ?? null) || !is_int($entry['heartbeat_at'] ?? null)
                || !is_string($entry['token'] ?? null)) {
                throw new EditLockException('Invalid edit-lock record.');
            }
            $lock = EditLockDto::fromArray([
                'resource_type' => $entry['resource_type'], 'resource_id' => $entry['resource_id'],
                'user' => $entry['user'], 'name' => $entry['name'], 'acquired_at' => $entry['acquired_at'],
                'heartbeat_at' => $entry['heartbeat_at'], 'token' => $entry['token'],
            ]);
            $canonicalKey = $this->key($lock->getResourceType(), $lock->getResourceId());
            // Zachova aktivni zamky pri aktualizaci formatu; dalsi zapis ulozi nove klice.
            $previousKey = hash('sha256', $lock->getResourceType() . "\0" . $lock->getResourceId());
            if ($key !== $canonicalKey && $key !== $previousKey) {
                throw new EditLockException('Edit-lock key does not match its resource.');
            }
            if (isset($locks[$canonicalKey])) {
                throw new EditLockException('Duplicate edit-lock resource.');
            }
            $locks[$canonicalKey] = $lock;
        }
        return $locks;
    }

    /**
     * Zapise uplny snimek do sousedniho docasneho souboru a atomicky jej prejmenuje.
     * Stejny adresar zachova stejny filesystem; trvaly mutex drzi ostatni procesy
     * mimo cely cyklus. Lokalni snimek se zmeni az po uspesnem rename.
     * Smycka zapisu pocita i s castecne zapsanym obsahem.
     *
     * @param array<string, EditLockDto> $next Mapa persistencnich klicu na nove snimky.
     * @throws EditLockException Pri chybe vytvoreni, zapisu, flush nebo rename.
     * @throws JsonException Pokud data nelze serializovat.
     */
    private function persist(array $next): void
    {
        $data = array_map(static fn(EditLockDto $lock): array => $lock->toArray(), $next);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = tempnam(dirname($this->file), '.edit-lock-');
        if ($temporary === false) {
            throw new EditLockException('Cannot create an edit-lock snapshot.');
        }
        try {
            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new EditLockException('Cannot open an edit-lock snapshot.');
            }
            try {
                $offset = 0;
                $length = strlen($json);
                while ($offset < $length) {
                    $written = fwrite($stream, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new EditLockException('Cannot write an edit-lock snapshot.');
                    }
                    $offset += $written;
                }
                if (!fflush($stream)) {
                    throw new EditLockException('Cannot flush an edit-lock snapshot.');
                }
            } finally {
                fclose($stream);
            }
            if (!rename($temporary, $this->file)) {
                throw new EditLockException('Cannot commit an edit-lock snapshot.');
            }
            $this->locks = $next;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Uvolni transakci, nikoli editacni zaznamy. Opakovane volani je bezpecne.
     */
    public function close(): void
    {
        if ($this->mutex !== null) {
            flock($this->mutex, LOCK_UN);
            fclose($this->mutex);
            $this->mutex = null;
        }
    }

    /**
     * Uvolni mutex jako pojistku, pokud volajici neuzavrel transakci explicitne.
     */
    public function __destruct()
    {
        $this->close();
    }
}
