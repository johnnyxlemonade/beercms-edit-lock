<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Exception;

use RuntimeException;
use Throwable;

/**
 * Zaklad chyb editacnich zamku a volitelnych udaju vlastnika pro aplikacni zpravy.
 * Persistencni chyby se vyhazuji; ocekavana odmitnuti sluzba vraci uvnitr vysledku.
 */
class EditLockException extends RuntimeException
{
    /**
     * Uchova pricinu chyby a volitelne udaje pro zobrazeni konfliktu.
     *
     * @param string|null $ownerName Zobrazovane jmeno vlastnika, pokud je znam.
     * @param string|null $ownerUser Identita vlastnika; token se do chyby neprenasi.
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?string $ownerName = null,
        private readonly ?string $ownerUser = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Vrati zobrazovane jmeno vlastnika, nebo null pokud chyba vlastnika neurcuje.
     */
    public function getOwnerName(): ?string
    {
        return $this->ownerName;
    }
    /**
     * Vrati identitu vlastnika, nebo null pokud chyba vlastnika neurcuje.
     */
    public function getOwnerUser(): ?string
    {
        return $this->ownerUser;
    }
}
