<?php

namespace App\Services\Airports\Sync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use Location\Coordinate;

/**
 * Works out the changes needed to bring airports in line with a sim's full airport list.
 *
 * Purely in-memory: matching is done against a snapshot of existing airports and nothing is written.
 */
class AirportSyncPlanner
{
    private const int MAX_IDENTIFIER_LENGTH = 15;

    /**
     * @param float $matchDistance Max distance (nm) for a CSV airport to be considered the same physical airport
     * @param float $minSeparation Min distance (nm) from any other airport for a new airport to be added
     */
    public function __construct(
        private readonly float $matchDistance = 1.0,
        private readonly float $minSeparation = 2.0,
    ) {
    }

    /**
     * @param list<SyncRow> $rows The sim's complete airport list
     * @param iterable<ExistingAirport> $existing All airports currently in the database
     * @param array<int> $activeAirportIds Airports with in-progress PIREPs
     */
    public function plan(SimType $sim, array $rows, iterable $existing, array $activeAirportIds = []): AirportSyncPlan
    {
        $plan = new AirportSyncPlan($sim);

        /** @var array<int, ExistingAirport> $airports */
        $airports = [];
        foreach ($existing as $airport) {
            $airports[$airport->id] = $airport;
        }
        $pool = array_filter($airports, fn (ExistingAirport $airport) => $airport->inPool());

        $matches = $this->matchRows($rows, $pool);
        $matchedRows = [];

        // Matched airports gain the sim and become open, base airports with the sim's identifier
        foreach ($matches as $airportId => [$row, $distance]) {
            $matchedRows[$row->row] = true;
            $airport = $airports[$airportId];

            $change = new AirportChange(
                $airport,
                identifier: $row->identifier,
                simTypes: [...$airport->simTypes, $sim->value],
                closed: false,
                isThirdParty: false,
                row: $row,
                distance: $distance,
            );

            if ($change->hasChanges()) {
                $plan->changes[$airportId] = $change;
            } else {
                $plan->unchanged++;
            }
        }

        // Unmatched base airports are no longer in this sim, and close once they're in no sims at all
        foreach ($pool as $airport) {
            if (isset($matches[$airport->id]) || $airport->isThirdParty || !in_array($sim->value, $airport->simTypes, true)) {
                continue;
            }

            $simTypes = array_values(array_diff($airport->simTypes, [$sim->value]));

            $plan->changes[$airport->id] = new AirportChange(
                $airport,
                identifier: $airport->identifier,
                simTypes: $simTypes,
                closed: $airport->closed || $simTypes === [],
                isThirdParty: false,
            );
        }

        $this->planAdditions($plan, $rows, $matchedRows, $pool);
        $this->resolveIdentifiers($plan, $airports);
        $this->assignClosedIdentifiers($plan, $airports);
        $this->addWarnings($plan, $sim, $activeAirportIds);

        return $plan;
    }

    /**
     * Pair CSV rows with existing airports: first by identifier, then by proximity
     *
     * @param list<SyncRow> $rows
     * @param array<int, ExistingAirport> $pool
     * @return array<int, array{0: SyncRow, 1: float}> Keyed by airport id
     */
    private function matchRows(array $rows, array $pool): array
    {
        $matches = [];
        $matchedRows = [];

        // Closed airports are found by the identifier they had before closing
        $byIdentifier = [];
        foreach ($pool as $airport) {
            $byIdentifier[self::key($airport->originalIdentifier())][] = $airport;
        }

        foreach ($rows as $row) {
            $candidates = [];
            foreach ($byIdentifier[self::key($row->identifier)] ?? [] as $airport) {
                if (isset($matches[$airport->id])) {
                    continue;
                }

                $distance = $this->distance($row, $airport);
                if ($distance <= $this->matchDistance) {
                    $candidates[] = [$distance, $airport->closed ? 1 : 0, $airport];
                }
            }

            if ($candidates) {
                usort($candidates, fn ($a, $b) => [$a[0], $a[1], $a[2]->id] <=> [$b[0], $b[1], $b[2]->id]);
                $matches[$candidates[0][2]->id] = [$row, $candidates[0][0]];
                $matchedRows[$row->row] = true;
            }
        }

        /** @var SpatialIndex<ExistingAirport> $index */
        $index = new SpatialIndex();
        foreach ($pool as $airport) {
            if (!isset($matches[$airport->id])) {
                $index->add($airport->lat, $airport->lon, $airport);
            }
        }

        // Closest pairs win, preferring base over third-party and open over closed airports
        $pairs = [];
        foreach ($rows as $row) {
            if (isset($matchedRows[$row->row])) {
                continue;
            }

            foreach ($index->near($row->lat, $row->lon, $this->matchDistance) as ['item' => $airport, 'distance' => $distance]) {
                $pairs[] = [$distance, $airport->isThirdParty ? 1 : 0, $airport->closed ? 1 : 0, $row->row, $airport->id, $row, $airport];
            }
        }

        usort($pairs, fn ($a, $b) => array_slice($a, 0, 5) <=> array_slice($b, 0, 5));

        foreach ($pairs as [$distance, , , , , $row, $airport]) {
            if (isset($matches[$airport->id]) || isset($matchedRows[$row->row])) {
                continue;
            }

            $matches[$airport->id] = [$row, $distance];
            $matchedRows[$row->row] = true;
        }

        return $matches;
    }

    /**
     * Unmatched rows become new airports, provided nothing that remains open is too close
     *
     * @param list<SyncRow> $rows
     * @param array<int, true> $matchedRows
     * @param array<int, ExistingAirport> $pool
     */
    private function planAdditions(AirportSyncPlan $plan, array $rows, array $matchedRows, array $pool): void
    {
        /** @var SpatialIndex<string> $open */
        $open = new SpatialIndex();
        foreach ($pool as $airport) {
            $change = $plan->changes[$airport->id] ?? null;
            if (!($change ? $change->closed : $airport->closed)) {
                $open->add($airport->lat, $airport->lon, $change ? $change->identifier : $airport->identifier);
            }
        }

        foreach ($rows as $row) {
            if (isset($matchedRows[$row->row])) {
                continue;
            }

            $nearby = $open->near($row->lat, $row->lon, $this->minSeparation);
            if ($nearby) {
                $plan->skipped[] = $this->issue($row, null, "Within {$nearby[0]['distance']}nm of {$nearby[0]['item']}", $nearby[0]['distance']);
                continue;
            }

            $plan->additions[] = $row;
            $open->add($row->lat, $row->lon, $row->identifier);
        }
    }

    /**
     * Drop renames and additions whose identifier is held by an airport that keeps it
     *
     * @param array<int, ExistingAirport> $airports
     */
    private function resolveIdentifiers(AirportSyncPlan $plan, array $airports): void
    {
        // Reverting one rename keeps its old identifier in use, which may block another, so repeat until stable
        do {
            $holders = $this->identifierHolders($plan, $airports);
            $reverted = false;

            foreach ($plan->changes as $id => $change) {
                if (!$change->identifierChanged() || $change->closing()) {
                    continue;
                }

                $holder = $holders[self::key($change->identifier)] ?? null;
                if ($holder === null || $holder === $id) {
                    continue;
                }

                $plan->conflicts[] = $this->issue(
                    $change->row,
                    $change->airport,
                    "Identifier is in use by {$airports[$holder]->identifier} (#{$holder})",
                    $change->distance
                );

                if ($change->reopening()) {
                    // Leave closed rather than reopening without its identifier
                    unset($plan->changes[$id]);
                } else {
                    $change->identifier = $change->airport->identifier;
                    if (!$change->hasChanges()) {
                        unset($plan->changes[$id]);
                    }
                }

                $reverted = true;
            }
        } while ($reverted);

        $holders = $this->identifierHolders($plan, $airports);
        $plan->additions = array_values(array_filter($plan->additions, function (SyncRow $row) use ($plan, $holders, $airports) {
            $holder = $holders[self::key($row->identifier)] ?? null;
            if ($holder === null) {
                return true;
            }

            $plan->conflicts[] = $this->issue($row, null, "Identifier is in use by {$airports[$holder]->identifier} (#{$holder})");

            return false;
        }));
    }

    /**
     * Airports that will hold their current identifier after the sync
     *
     * @param array<int, ExistingAirport> $airports
     * @return array<string, int> Identifier key => airport id
     */
    private function identifierHolders(AirportSyncPlan $plan, array $airports): array
    {
        $holders = [];
        foreach ($airports as $id => $airport) {
            if (!($plan->changes[$id] ?? null)?->vacatesIdentifier()) {
                $holders[self::key($airport->identifier)] = $id;
            }
        }

        return $holders;
    }

    /**
     * Give closing airports a unique [X] identifier
     *
     * @param array<int, ExistingAirport> $airports
     */
    private function assignClosedIdentifiers(AirportSyncPlan $plan, array $airports): void
    {
        $taken = [];
        foreach ($airports as $id => $airport) {
            $change = $plan->changes[$id] ?? null;
            if (!$change?->closing()) {
                $taken[self::key($change ? $change->identifier : $airport->identifier)] = true;
            }
        }
        foreach ($plan->additions as $row) {
            $taken[self::key($row->identifier)] = true;
        }

        foreach ($plan->changes as $id => $change) {
            if (!$change->closing()) {
                continue;
            }

            $base = ExistingAirport::CLOSED_PREFIX . $change->airport->identifier;
            $candidates = [$base, ...array_map(fn ($n) => "{$base}-{$n}", range(2, 9)), ExistingAirport::CLOSED_PREFIX . "#{$id}"];

            foreach ($candidates as $candidate) {
                if (strlen($candidate) <= self::MAX_IDENTIFIER_LENGTH && !isset($taken[self::key($candidate)])) {
                    $change->identifier = $candidate;
                    $taken[self::key($candidate)] = true;
                    break;
                }
            }
        }
    }

    /**
     * @param array<int> $activeAirportIds
     */
    private function addWarnings(AirportSyncPlan $plan, SimType $sim, array $activeAirportIds): void
    {
        $active = array_flip($activeAirportIds);

        foreach ($plan->changes as $id => $change) {
            if (!$change->vacatesIdentifier()) {
                continue;
            }

            $identifier = $change->airport->identifier;
            $verb = $change->closing() ? 'closed' : 'renamed';

            if ($change->airport->isHub) {
                $plan->warnings[] = ['airport_id' => $id, 'identifier' => $identifier, 'message' => "Hub airport {$verb}"];
            }
            if (isset($active[$id])) {
                $plan->warnings[] = ['airport_id' => $id, 'identifier' => $identifier, 'message' => "Airport with in-progress PIREPs {$verb}"];
            }

            $otherSims = array_diff($change->airport->simTypes, [$sim->value]);
            if ($change->renaming() && $otherSims) {
                $labels = implode(', ', array_map(fn ($value) => SimType::from($value)->label(), $otherSims));
                $plan->warnings[] = ['airport_id' => $id, 'identifier' => $identifier, 'message' => "Rename also applies to {$labels}"];
            }
        }
    }

    /**
     * @return array{row: int|null, airport_id: int|null, identifier: string, wanted: string|null, name: string, distance: float|null, reason: string}
     */
    private function issue(?SyncRow $row, ?ExistingAirport $airport, string $reason, ?float $distance = null): array
    {
        return [
            'row' => $row?->row,
            'airport_id' => $airport?->id,
            'identifier' => $airport->identifier ?? $row->identifier ?? '',
            'wanted' => $row?->identifier,
            'name' => $row->name ?? $airport->name ?? '',
            'distance' => $distance,
            'reason' => $reason,
        ];
    }

    private function distance(SyncRow $row, ExistingAirport $airport): float
    {
        return Airport::distanceBetween(new Coordinate($row->lat, $row->lon), new Coordinate($airport->lat, $airport->lon));
    }

    /**
     * Identifiers are unique case-insensitively in the database
     */
    private static function key(string $identifier): string
    {
        return strtoupper(trim($identifier));
    }
}
