<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Dto;

use BeersCms\EditLock\Exception\EditLockException;
use InvalidArgumentException;

/**
 * Immutable vysledek operace: bud uspesny zamek, nebo typovana chyba.
 * Ocekavany konflikt ci ztrata vlastnictvi se vraci jako data, nevyhazuje se.
 */
final class EditLockResultDto
{
    /**
     * Vynuti vzajemnou vylucnost zamku a chyby podle uspechu operace.
     *
     * @throws InvalidArgumentException Pokud kombinace uspechu, zamku a chyby neni konzistentni.
     */
    public function __construct(
        private readonly bool $success,
        private readonly ?EditLockDto $lock = null,
        private readonly ?EditLockException $error = null,
    ) {
        if (($success && ($lock === null || $error !== null)) || (!$success && ($lock !== null || $error === null))) {
            throw new InvalidArgumentException('A successful result needs a lock; a failed result needs an error.');
        }
    }

    /**
     * Rozlisuje potvrzenou operaci od ocekavaneho odmitnuti.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
    /**
     * Vrati snimek pri uspechu; u release jde o jiz odstraneny zamek.
     *
     * @return EditLockDto|null Null pri neuspesne operaci.
     */
    public function getLock(): ?EditLockDto
    {
        return $this->lock;
    }
    /**
     * Vrati typovanou pricinu odmitnuti pro rozhodnuti aplikacniho adapteru.
     *
     * @return EditLockException|null Null pri uspesne operaci.
     */
    public function getError(): ?EditLockException
    {
        return $this->error;
    }
    /**
     * Vrati zpravu chyby, pri uspechu prazdny retezec.
     */
    public function getMessage(): string
    {
        return $this->error === null ? '' : $this->error->getMessage();
    }
    /**
     * Vrati potvrzeny zamek, nebo vyhodi typovanou pricinu odmitnuti.
     * Umoznuje skladat operace ve stejne transakci bez opakovaneho rozbalovani.
     * @throws EditLockException Pri neuspesne operaci.
     */
    public function requireLock(): EditLockDto
    {
        if ($this->lock !== null) {
            return $this->lock;
        }
        throw $this->error ?? new EditLockException('Missing operation result.');
    }

}
