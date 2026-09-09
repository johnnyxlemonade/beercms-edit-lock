<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Tests\Support;

use BeersCms\EditLock\Dto\EditLockDto;
use BeersCms\EditLock\Storage\EditLockStorageInterface;

/**
 * Pametove uloziste pro testy pravidel sluzby bez filesystemu.
 * Simuluje persistenci, nikoli soubezne procesy; ty overuji integracni testy.
 */
final class InMemoryStorage implements EditLockStorageInterface
{
    /** @var array<string, EditLockDto> */
    private array $locks = [];
    /**
     * V pametovem testu nema transakce externi prostredky k zamykani nebo uvolneni.
     */
    public function open(): void {}
    /**
     * V pametovem testu nema transakce externi prostredky k zamykani nebo uvolneni.
     */
    public function close(): void {}
    /**
     * Vrati ulozeny snimek bez posouzeni vlastnictvi nebo TTL.
     *
     * @return EditLockDto|null Null pokud dvojice typu a ID nema zaznam.
     */
    public function find(string $resourceType, string $resourceId): ?EditLockDto
    {
        return $this->locks[$resourceType . "\0" . $resourceId] ?? null;
    }
    /**
     * Ulozi nebo nahradi snimek pod jeho typem a ID v ramci transakce.
     *
     */
    public function put(EditLockDto $lock): void
    {
        $this->locks[$lock->getResourceType() . "\0" . $lock->getResourceId()] = $lock;
    }
    /**
     * Odstrani zaznam daneho typu a ID; neexistujici zaznam neni chyba vlastnictvi.
     *
     */
    public function remove(string $resourceType, string $resourceId): void
    {
        unset($this->locks[$resourceType . "\0" . $resourceId]);
    }
    /**
     * Odstrani zaznamy s heartbeat mensim nebo rovnym dodane hranici.
     *
     * @param int $heartbeatCutoff Unix timestamp v sekundach vypocteny sluzbou jako cas minus TTL.
     */
    public function removeExpired(int $heartbeatCutoff): void
    {
        $this->locks = array_filter($this->locks, static fn(EditLockDto $lock): bool => $lock->getHeartbeatAt() > $heartbeatCutoff);
    }
}
