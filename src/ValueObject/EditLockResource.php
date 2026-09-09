<?php

declare(strict_types=1);

namespace BeersCms\EditLock\ValueObject;

use InvalidArgumentException;

/** Nemenny obecny resource; aplikace odpovida za kanonizaci trvaleho ID. */
final class EditLockResource
{
    /**
     * @throws InvalidArgumentException Pokud typ nebo ID chybi.
     */
    public function __construct(private readonly string $type, private readonly string $id)
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('Resource type and ID must not be empty.');
        }
    }

    /** Typ oddeluje identity ruznych druhu zaznamu. */
    public function getType(): string
    {
        return $this->type;
    }

    /** Kanonicke ID uvnitr typu resource. */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Vytvori nezavisly resource pred pridelenim trvale identity.
     * Prefix urcuje aplikace; balicek neinterpretuje jeho vyznam.
     * @throws \Exception Pri selhani generatoru nahodnych hodnot.
     */
    public static function temporary(string $type, string $prefix): self
    {
        return new self($type, $prefix . 'new:' . bin2hex(random_bytes(16)));
    }

    /** Obnovi pouze docasne ID z daneho prostoru; vlastnictvi dale overuje sluzba. */
    public static function restoreTemporary(string $type, string $prefix, string $id): ?self
    {
        return preg_match('~^' . preg_quote($prefix, '~') . 'new:[a-f0-9]{32}$~D', $id) === 1
            ? new self($type, $id)
            : null;
    }
}
