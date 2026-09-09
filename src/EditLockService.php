<?php

declare(strict_types=1);

namespace BeersCms\EditLock;

use BeersCms\EditLock\Dto\EditLockDto;
use BeersCms\EditLock\Dto\EditLockResultDto;
use BeersCms\EditLock\Exception\LockConflictException;
use BeersCms\EditLock\Exception\LockNotOwnedException;
use BeersCms\EditLock\Storage\EditLockStorageInterface;
use BeersCms\EditLock\ValueObject\EditLockResource;
use InvalidArgumentException;

/**
 * Sluzba pro spravu editacnich zamku nad libovolnym resource.
 *
 * Resi pravidla ziskani, obnoveni, overeni vlastnictvi a uvolneni zamku.
 * Fyzicky zpusob ulozeni deleguje na EditLockStorageInterface.
 * Jedna instance predstavuje jednu transakci; po close se jiz nepouziva.
 */
final class EditLockService
{
    public const TTL = 900;
    private readonly int $now;
    private bool $closed = false;

    /**
     * Otevre transakci a odstrani zaznamy expirovane v okamziku jejiho zacatku.
     * Cas se behem transakce nemeni, aby overeni a chraneny zapis tvorily jeden celek.
     *
     * @param int $ttl Doba platnosti od posledniho heartbeat v sekundach; musi byt kladna.
     * @param int|null $now Pevny Unix timestamp v sekundach pro testy; null pouzije aktualni cas.
     * @throws InvalidArgumentException Pokud TTL neni kladne.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri selhani uloziste.
     * @throws \JsonException Pokud uklid nelze serializovat.
     */
    public function __construct(private readonly EditLockStorageInterface $storage, private readonly int $ttl = self::TTL, ?int $now = null)
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive.');
        }
        $this->storage->open();
        $this->now = $now ?? time();
        $this->storage->removeExpired($this->now - $this->ttl);
    }

    /**
     * Ziska volny zamek nebo obnovi zamek se shodnym uzivatelem i tokenem.
     * Samotna shoda uzivatele nestaci, aby jina session neprevzala otevrenou editaci.
     *
     * @param string $type Typ resource; spolu s ID urcuje identitu zamku.
     * @param string $id Kanonicke ID dodane aplikaci; sluzba neinterpretuje cesty ani slugy.
     * @param string $user Identita prihlaseneho uzivatele overena aplikaci.
     * @param string|null $name Jmeno pro zobrazeni konfliktu; null pouzije identitu uzivatele.
     * @param string $token Dosavadni token pro obnoveni; u noveho zamku muze byt prazdny.
     * @return EditLockResultDto Konflikt je neuspesny vysledek s LockConflictException.
     * @throws InvalidArgumentException Pri prazdnem typu nebo ID.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri selhani uloziste.
     * @throws \JsonException Pokud zaznam nelze serializovat.
     * @throws \Exception Pokud nelze bezpecne vygenerovat nahodny token.
     */
    public function acquire(string $type, string $id, string $user, ?string $name = null, string $token = ''): EditLockResultDto
    {
        $this->validateIdentity($type, $id);
        $existing = $this->storage->find($type, $id);
        if ($existing !== null) {
            $owned = $this->assertOwned($type, $id, $user, $token);
            if (!$owned->isSuccess()) {
                return new EditLockResultDto(false, null, new LockConflictException(
                    $owned->getMessage(),
                    ownerName: $existing->getName(),
                    ownerUser: $existing->getUser(),
                ));
            }
            return $this->refresh($type, $id, $user, $token);
        }
        $lock = new EditLockDto($type, $id, $user, $name ?? $user, $this->now, $this->now, bin2hex(random_bytes(32)));
        $this->storage->put($lock);
        return new EditLockResultDto(true, $lock);
    }

    /**
     * Overi platnost, identitu uzivatele a token bez obnoveni heartbeat.
     * Expirace nastava i presne na hranici heartbeat + TTL. Token se porovnava
     * pomoci hash_equals; volajici musi udrzet transakci i po dobu chraneneho zapisu.
     *
     * @param string $token Tajny token konkretni editace, nikoli pouze identita uzivatele.
     * @return EditLockResultDto Chybejici, expirovany nebo cizi zamek vraci LockNotOwnedException ve vysledku.
     * @throws InvalidArgumentException Pri prazdnem typu nebo ID.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri selhani uloziste.
     */
    public function assertOwned(string $type, string $id, string $user, string $token): EditLockResultDto
    {
        $this->validateIdentity($type, $id);
        $lock = $this->storage->find($type, $id);
        if ($lock === null || $lock->getHeartbeatAt() + $this->ttl <= $this->now) {
            return new EditLockResultDto(false, null, new LockNotOwnedException('The edit lock is missing or expired.'));
        }
        if ($lock->getUser() !== $user || !hash_equals($lock->getToken(), $token)) {
            return new EditLockResultDto(false, null, new LockNotOwnedException(
                'The resource is being edited by ' . $lock->getName() . ' (' . $lock->getUser() . ').',
                ownerName: $lock->getName(),
                ownerUser: $lock->getUser(),
            ));
        }
        return new EditLockResultDto(true, $lock);
    }

    /**
     * Obnovi heartbeat pouze po uspesnem overeni vlastnictvi.
     * Puvodni DTO zustane nemenne; ulozi se nova instance se stejnym tokenem.
     *
     * @return EditLockResultDto Aktualizovany zamek nebo neuspesny vysledek overeni.
     * @throws InvalidArgumentException Pri prazdnem typu nebo ID.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri selhani uloziste.
     * @throws \JsonException Pokud zaznam nelze serializovat.
     */
    public function refresh(string $type, string $id, string $user, string $token): EditLockResultDto
    {
        $owned = $this->assertOwned($type, $id, $user, $token);
        $lock = $owned->getLock();
        if ($lock === null) {
            return $owned;
        }
        $updated = $lock->withHeartbeat($this->now);
        $this->storage->put($updated);
        return new EditLockResultDto(true, $updated);
    }

    /**
     * Odstrani pouze platny zamek vlastneny uzivatelem s odpovidajicim tokenem.
     * Uspesny vysledek obsahuje DTO odstraneneho zamku, nikoli dukaz dalsiho vlastnictvi.
     *
     * @throws InvalidArgumentException Pri prazdnem typu nebo ID.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri selhani uloziste.
     * @throws \JsonException Pokud zbyvajici zaznamy nelze serializovat.
     */
    public function release(string $type, string $id, string $user, string $token): EditLockResultDto
    {
        $owned = $this->assertOwned($type, $id, $user, $token);
        if ($owned->isSuccess()) {
            $this->storage->remove($type, $id);
        }
        return $owned;
    }

    /**
     * Dokonci chraneny zapis a uzavre transakci. Bez pokracovani zamek uvolni.
     * Pri pokracovani prevede pripadnou docasnou identitu na trvalou a obnovi
     * heartbeat. Cilovy zamek musi byt ziskan pred uvolnenim puvodniho.
     *
     * @param EditLockResource|null $target Trvala identita po zapisu, pokud se zmenila.
     * @return EditLockDto|null Zamek pro dalsi editaci; null po konecnem ulozeni.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri konfliktu, ztrate vlastnictvi nebo chybe uloziste.
     * @throws \JsonException Pri chybe serializace.
     * @throws \Exception Pri selhani generatoru tokenu.
     */
    public function complete(EditLockDto $lock, bool $keep = false, ?EditLockResource $target = null): ?EditLockDto
    {
        $this->assertOwned($lock->getResourceType(), $lock->getResourceId(), $lock->getUser(), $lock->getToken())->requireLock();
        if (!$keep) {
            $this->release($lock->getResourceType(), $lock->getResourceId(), $lock->getUser(), $lock->getToken())->requireLock();
            $this->close();
            return null;
        }
        if ($target !== null && ($target->getType() !== $lock->getResourceType() || $target->getId() !== $lock->getResourceId())) {
            $next = $this->acquire($target->getType(), $target->getId(), $lock->getUser(), $lock->getName())->requireLock();
            $this->release($lock->getResourceType(), $lock->getResourceId(), $lock->getUser(), $lock->getToken())->requireLock();
            $lock = $next;
        }
        $this->refresh($lock->getResourceType(), $lock->getResourceId(), $lock->getUser(), $lock->getToken())->requireLock();
        $this->close();
        return $lock;
    }

    /**
     * Odmitne prazdnou identitu; kanonizace konkretniho resource patri aplikaci.
     *
     * @throws InvalidArgumentException Pri prazdnem typu nebo ID.
     */
    private function validateIdentity(string $type, string $id): void
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('Resource type and ID must not be empty.');
        }
    }

    /**
     * Ukonci transakci nejvyse jednou; editacni zaznamy ponecha pro dalsi requesty.
     * Opakovane close ani destruktor nesmi uzavrit pozdejsi transakci sdileneho uloziste.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->storage->close();
            $this->closed = true;
        }
    }
    /**
     * Pojistka pro ukonceni transakce; bezne ma volajici pouzit close ve finally.
     */
    public function __destruct()
    {
        $this->close();
    }
}
