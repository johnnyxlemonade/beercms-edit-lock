<?php

declare(strict_types=1);

namespace BeersCms\EditLock\Dto;

/**
 * Immutable DTO reprezentujici aktualni stav editacniho zamku.
 * Casove udaje jsou Unix timestampy v sekundach; DTO samo neresi TTL.
 *
 * @phpstan-type SerializedLock array{resource_type: string, resource_id: string, user: string,
 *     name: string, acquired_at: int, heartbeat_at: int, token: string}
 */
final class EditLockDto
{
    /**
     * Vytvori nemenny snimek zamku z typovanych udaju.
     * Overeni vstupniho JSON a pravidla vlastnictvi patri ulozisti a sluzbe.
     *
     * @param string $resourceType Typ resource oddelujici stejne ID ruznych druhu zaznamu.
     * @param string $resourceId Kanonicka identita resource dodana aplikaci.
     * @param string $user Identita vlastnika.
     * @param string $name Zobrazovane jmeno vlastnika.
     * @param int $acquiredAt Unix timestamp prvniho ziskani zamku v sekundach.
     * @param int $heartbeatAt Unix timestamp posledniho obnoveni v sekundach.
     * @param string $token Tajny token editace; nepatri do verejneho vypisu konfliktu.
     */
    public function __construct(
        private readonly string $resourceType,
        private readonly string $resourceId,
        private readonly string $user,
        private readonly string $name,
        private readonly int $acquiredAt,
        private readonly int $heartbeatAt,
        private readonly string $token,
    ) {}

    /**
     * Typ resource tvorici prvni cast identity zamku.
     */
    public function getResourceType(): string
    {
        return $this->resourceType;
    }
    /**
     * Kanonicke ID resource v ramci jeho typu.
     */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }
    /**
     * Identita vlastnika pouzivana pri overeni opravneni.
     */
    public function getUser(): string
    {
        return $this->user;
    }
    /**
     * Jmeno vlastnika urcene pro informaci o konfliktu.
     */
    public function getName(): string
    {
        return $this->name;
    }
    /**
     * Unix timestamp prvniho ziskani v sekundach; heartbeat jej nemeni.
     */
    public function getAcquiredAt(): int
    {
        return $this->acquiredAt;
    }
    /**
     * Unix timestamp posledni aktivity v sekundach pro vyhodnoceni TTL.
     */
    public function getHeartbeatAt(): int
    {
        return $this->heartbeatAt;
    }
    /**
     * Tajny token potrebny vedle identity uzivatele k overeni vlastnictvi.
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Vrati novy snimek s jinym heartbeat; puvodni instance, token i cas ziskani zustanou zachovany.
     *
     * @param int $time Novy Unix timestamp aktivity v sekundach.
     */
    public function withHeartbeat(int $time): self
    {
        return new self(
            $this->resourceType,
            $this->resourceId,
            $this->user,
            $this->name,
            $this->acquiredAt,
            $time,
            $this->token,
        );
    }

    /**
     * Obnovi DTO z jiz overene serializovane struktury.
     * Nevaliduje neduveryhodny JSON; tuto hranici obsluhuje uloziste.
     *
     * @param SerializedLock $data Kompletni typovany zaznam.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['resource_type'],
            $data['resource_id'],
            $data['user'],
            $data['name'],
            $data['acquired_at'],
            $data['heartbeat_at'],
            $data['token'],
        );
    }

    /**
     * Vrati stabilni format pro persistenci vcetne tajneho tokenu.
     * Vysledek neni urcen pro verejnou odpoved pri konfliktu.
     *
     * @return SerializedLock
     */
    public function toArray(): array
    {
        return ['resource_type' => $this->resourceType, 'resource_id' => $this->resourceId,
            'user' => $this->user, 'name' => $this->name, 'acquired_at' => $this->acquiredAt,
            'heartbeat_at' => $this->heartbeatAt, 'token' => $this->token];
    }
}
