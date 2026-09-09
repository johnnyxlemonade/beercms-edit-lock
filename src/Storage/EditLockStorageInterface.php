<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Storage;

use BeersCms\EditLock\Dto\EditLockDto;

/**
 * Persistencni kontrakt pro exkluzivni transakci nad editacnimi zamky.
 * Vsechny operace krome open a close vyzaduji otevrenou transakci.
 * Implementace neresi opravneni ani volbu TTL; hranici expirace dodava sluzba.
 * Exkluzivita musi trvat pres overeni zamku az po chraneny aplikacni zapis.
 */
interface EditLockStorageInterface
{
    /**
     * Otevre exkluzivni transakci; soubezne operace nad stejnym ulozistem musi cekat.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe otevreni nebo nacteni.
     */
    public function open(): void;
    /**
     * Vrati ulozeny snimek bez posouzeni vlastnictvi nebo TTL.
     *
     * @return EditLockDto|null Null pokud dvojice typu a ID nema zaznam.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pokud transakce neni otevrena.
     */
    public function find(string $resourceType, string $resourceId): ?EditLockDto;
    /**
     * Ulozi nebo nahradi snimek pod jeho typem a ID v ramci transakce.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zaznam nelze serializovat.
     */
    public function put(EditLockDto $lock): void;
    /**
     * Odstrani zaznam daneho typu a ID; neexistujici zaznam neni chyba vlastnictvi.
     *
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zbyvajici zaznamy nelze serializovat.
     */
    public function remove(string $resourceType, string $resourceId): void;
    /**
     * Odstrani zaznamy s heartbeat mensim nebo rovnym dodane hranici.
     *
     * @param int $heartbeatCutoff Unix timestamp v sekundach vypocteny sluzbou jako cas minus TTL.
     * @throws \BeersCms\EditLock\Exception\EditLockException Pri chybe transakce nebo zapisu.
     * @throws \JsonException Pokud zbyvajici zaznamy nelze serializovat.
     */
    public function removeExpired(int $heartbeatCutoff): void;
    /**
     * Uvolni transakci, nikoli editacni zaznamy. Opakovane volani je bezpecne.
     */
    public function close(): void;
}
