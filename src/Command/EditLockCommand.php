<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Command;

use InvalidArgumentException;

/**
 * Nemenny prikaz nad existujicim zamkem bez znalosti HTTP nebo session.
 * Identitu uzivatele musi volajici overit pred sestavenim prikazu.
 */
final class EditLockCommand
{
    /**
     * Vyzaduje explicitni typ a ID; prazdny token posoudi pravidla sluzby.
     *
     * @param string $resourceType Typ resource bez implicitni vychozi hodnoty.
     * @param string $resourceId Kanonicke ID dodane aplikaci.
     * @param string $user Overena identita uzivatele.
     * @param string $token Token editace predany klientem.
     * @throws InvalidArgumentException Pri prazdnem typu, ID nebo uzivateli.
     */
    public function __construct(
        private readonly EditLockOperation $operation,
        private readonly string $resourceType,
        private readonly string $resourceId,
        private readonly string $user,
        private readonly string $token,
    ) {
        if ($resourceType === '' || $resourceId === '' || $user === '') {
            throw new InvalidArgumentException('Resource type, ID and user must not be empty.');
        }
    }

    /** Operace urcena typovanym vyctem podporovanych prikazu. */
    public function getOperation(): EditLockOperation
    {
        return $this->operation;
    }

    /** Typ odlisujici shodna ID ruznych druhu zaznamu. */
    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    /** Kanonicka identita zaznamu v ramci typu. */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /** Identita uzivatele, jejiz puvod overil volajici. */
    public function getUser(): string
    {
        return $this->user;
    }

    /** Tajny token pro overeni vlastnictvi; neni urcen pro verejne vypisy. */
    public function getToken(): string
    {
        return $this->token;
    }
}
