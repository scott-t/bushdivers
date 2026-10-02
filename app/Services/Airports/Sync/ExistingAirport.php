<?php

namespace App\Services\Airports\Sync;

/**
 * Lightweight snapshot of an airport row, used to plan a sync without hydrating models
 */
final readonly class ExistingAirport
{
    public const string CLOSED_PREFIX = '[X]';

    /**
     * Columns required by fromRow()
     */
    public const array COLUMNS = ['id', 'identifier', 'name', 'lat', 'lon', 'sim_type', 'is_thirdparty', 'closed', 'user_id', 'is_hub'];

    /**
     * @param list<string> $simTypes
     */
    public function __construct(
        public int $id,
        public string $identifier,
        public string $name,
        public float $lat,
        public float $lon,
        public array $simTypes,
        public bool $isThirdParty = false,
        public bool $closed = false,
        public ?int $userId = null,
        public bool $isHub = false,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $simTypes = is_string($row->sim_type) ? json_decode($row->sim_type, true) : $row->sim_type;

        return new self(
            id: (int) $row->id,
            identifier: (string) $row->identifier,
            name: (string) $row->name,
            lat: (float) $row->lat,
            lon: (float) $row->lon,
            simTypes: AirportChange::normaliseSimTypes($simTypes ?? []),
            isThirdParty: (bool) $row->is_thirdparty,
            closed: (bool) $row->closed,
            userId: $row->user_id === null ? null : (int) $row->user_id,
            isHub: (bool) $row->is_hub,
        );
    }

    /**
     * Base airports and third-party airports take part in matching, user (campsite) airports don't
     */
    public function inPool(): bool
    {
        return $this->userId === null;
    }

    /**
     * Identifier as it was before the airport was closed (ie, without the [X] marker or any de-duplication suffix)
     */
    public function originalIdentifier(): string
    {
        if (!$this->closed || !str_starts_with($this->identifier, self::CLOSED_PREFIX)) {
            return $this->identifier;
        }

        return preg_replace('/-\d+$/', '', substr($this->identifier, strlen(self::CLOSED_PREFIX)));
    }
}
