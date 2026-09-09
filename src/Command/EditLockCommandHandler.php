<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Command;

use BeersCms\EditLock\Dto\EditLockResultDto;
use BeersCms\EditLock\EditLockService;
use BeersCms\EditLock\Exception\EditLockException;
use InvalidArgumentException;
use JsonException;

/**
 * Prevede typovany prikaz na operaci sluzby bez vlastnich pravidel zamku.
 * Neotevira ani neuzavira transakci; jeji rozsah urcuje volajici sluzby.
 */
final class EditLockCommandHandler
{
    /** Pouzije sluzbu s jiz otevrenou transakci. */
    public function __construct(private readonly EditLockService $service) {}

    /**
     * Deleguje kontrolu vlastnictvi, TTL a tokenu vyhradne na sluzbu.
     *
     * @return EditLockResultDto Vysledek sluzby vcetne pripadneho odmitnuti.
     * @throws InvalidArgumentException Pri neplatne identite resource.
     * @throws EditLockException Pri chybe uloziste.
     * @throws JsonException Pokud nelze serializovat stav uloziste.
     */
    public function handle(EditLockCommand $command): EditLockResultDto
    {
        return match ($command->getOperation()) {
            EditLockOperation::Refresh => $this->service->refresh(
                $command->getResourceType(),
                $command->getResourceId(),
                $command->getUser(),
                $command->getToken(),
            ),
            EditLockOperation::Release => $this->service->release(
                $command->getResourceType(),
                $command->getResourceId(),
                $command->getUser(),
                $command->getToken(),
            ),
        };
    }
}
