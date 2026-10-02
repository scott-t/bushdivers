<?php

namespace App\Services\Airports\Sync;

use App\Models\Enums\SimType;

/**
 * The planned end state of an existing airport
 */
class AirportChange
{
    /**
     * @param list<string> $simTypes
     */
    public function __construct(
        public readonly ExistingAirport $airport,
        public string $identifier,
        public array $simTypes,
        public bool $closed,
        public bool $isThirdParty,
        public readonly ?SyncRow $row = null,
        public readonly ?float $distance = null,
    ) {
        $this->simTypes = self::normaliseSimTypes($simTypes);
    }

    /**
     * Unique, known sim type values in a consistent order
     *
     * @param array<mixed> $simTypes
     * @return list<string>
     */
    public static function normaliseSimTypes(array $simTypes): array
    {
        $values = array_map(fn ($type) => $type instanceof SimType ? $type->value : $type, $simTypes);

        return array_values(array_filter(
            array_map(fn (SimType $type) => $type->value, SimType::cases()),
            fn ($value) => in_array($value, $values, true)
        ));
    }

    public function hasChanges(): bool
    {
        return $this->identifierChanged()
            || $this->closed !== $this->airport->closed
            || $this->isThirdParty !== $this->airport->isThirdParty
            || $this->simTypes !== $this->airport->simTypes;
    }

    public function identifierChanged(): bool
    {
        return $this->identifier !== $this->airport->identifier;
    }

    /**
     * Whether the airport will no longer hold its current identifier
     */
    public function vacatesIdentifier(): bool
    {
        return $this->identifierChanged() || $this->closing();
    }

    public function closing(): bool
    {
        return $this->closed && !$this->airport->closed;
    }

    public function reopening(): bool
    {
        return !$this->closed && $this->airport->closed;
    }

    public function promoting(): bool
    {
        return $this->airport->isThirdParty && !$this->isThirdParty;
    }

    public function renaming(): bool
    {
        if (!$this->identifierChanged() || $this->closing()) {
            return false;
        }

        // Reopening restores the original identifier, so only count it as a rename if it differs from that
        return !$this->reopening()
            || strcasecmp($this->airport->originalIdentifier(), $this->identifier) !== 0;
    }

    /**
     * @return list<string>
     */
    public function addedSimTypes(): array
    {
        return array_values(array_diff($this->simTypes, $this->airport->simTypes));
    }

    /**
     * @return list<string>
     */
    public function removedSimTypes(): array
    {
        return array_values(array_diff($this->airport->simTypes, $this->simTypes));
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        $actions = [];

        if ($this->closing()) {
            $actions[] = 'close';
        }
        if ($this->reopening()) {
            $actions[] = 'reopen';
        }
        if ($this->promoting()) {
            $actions[] = 'promote';
        }
        if ($this->renaming()) {
            $actions[] = 'rename';
        }
        if ($this->addedSimTypes()) {
            $actions[] = 'tag';
        }
        if ($this->removedSimTypes() && !$this->closing()) {
            $actions[] = 'untag';
        }

        return $actions;
    }
}
