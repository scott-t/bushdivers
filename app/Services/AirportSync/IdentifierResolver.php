<?php

namespace App\Services\AirportSync;

/**
 * Works out the final identifier of every airport once a changeset is applied, and reports
 * anything that would violate uniqueness or touch an airport twice. Pure logic: the caller
 * supplies the live identifier map so this can be re-run on every review decision.
 */
class IdentifierResolver
{
    public const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{0,14}$/';

    /**
     * @param  array  $changeset  see AirportSyncService::analyse()
     * @param  array<int, string>  $currentIdentifiers  airport id => identifier, for every airport
     * @param  array<int, true>  $lockedAirportIds  airports that must not change identifier (active pireps)
     * @return array{
     *     identifier_changes: array<int, string>,
     *     creates: array<string, string>,
     *     modify: array<int, array{op_id: string, promote: bool}>,
     *     untag: array<int, string>,
     *     conflicts: array<int, array>,
     *     unresolved: int,
     * }
     */
    public function plan(array $changeset, array $currentIdentifiers, array $lockedAirportIds = [], bool $includeUntags = true): array
    {
        $final = [];      // key => identifier, only for keys whose identifier is new or changed
        $claims = [];     // airport id => [op ids] (modify and untag both claim the airport)
        $modify = [];
        $untag = [];
        $creates = [];
        $conflicts = [];
        $unresolved = 0;

        foreach ($changeset['operations'] ?? [] as $op) {
            if (SyncOp::isUnresolved($op)) {
                $unresolved++;
            }

            $outcome = SyncOp::outcome($op, $includeUntags);

            if ($outcome['kind'] === SyncOp::OUTCOME_CREATE) {
                $creates[$op['id']] = $this->normalise($op['incoming']['identifier'] ?? '');
                $final["op:{$op['id']}"] = $creates[$op['id']];
                continue;
            }

            if ($outcome['kind'] === SyncOp::OUTCOME_NONE) {
                continue;
            }

            $airportId = $outcome['airport_id'];
            $claims[$airportId][] = $op['id'];

            if (! array_key_exists($airportId, $currentIdentifiers)) {
                $conflicts[] = $this->conflict('airport_missing', $op['incoming']['identifier'] ?? null, [
                    ['kind' => 'airport', 'airport_id' => $airportId, 'op_ids' => [$op['id']]],
                ], "Airport #{$airportId} no longer exists.");
                continue;
            }

            if ($outcome['kind'] === SyncOp::OUTCOME_UNTAG) {
                $untag[$airportId] = $op['id'];
                continue;
            }

            $modify[$airportId] = ['op_id' => $op['id'], 'promote' => $outcome['promote']];
            $final["airport:{$airportId}"] = $this->normalise($op['incoming']['identifier'] ?? '');
        }

        foreach ($changeset['identifier_overrides'] ?? [] as $airportId => $identifier) {
            $final["airport:{$airportId}"] = $this->normalise($identifier);
        }

        foreach ($claims as $airportId => $opIds) {
            if (count($opIds) > 1) {
                $conflicts[] = $this->conflict('airport_claimed_twice', $currentIdentifiers[$airportId] ?? null, [
                    ['kind' => 'airport', 'airport_id' => $airportId, 'current_identifier' => $currentIdentifiers[$airportId] ?? null, 'op_ids' => $opIds],
                ], 'More than one incoming airport has been matched to ' . ($currentIdentifiers[$airportId] ?? "#{$airportId}") . '. Pick a different action for all but one.');
            }
        }

        // Drop no-op "changes" so they don't count as renames
        $identifierChanges = [];
        foreach ($final as $key => $identifier) {
            if (str_starts_with($key, 'airport:')) {
                $airportId = (int) substr($key, 8);

                if ($this->normalise($currentIdentifiers[$airportId] ?? '') === $identifier) {
                    unset($final[$key]);
                    continue;
                }

                $identifierChanges[$airportId] = $identifier;

                if (isset($lockedAirportIds[$airportId])) {
                    $conflicts[] = $this->conflict('active_pireps', $identifier, [
                        $this->airportParty($airportId, $currentIdentifiers, $claims),
                    ], ($currentIdentifiers[$airportId] ?? "#{$airportId}") . " has an in-progress flight and can't be renamed to {$identifier} yet.");
                }
            }

            if (! preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
                $conflicts[] = $this->conflict('invalid_identifier', $identifier, [
                    $this->party($key, $currentIdentifiers, $claims, $changeset),
                ], "'{$identifier}' is not a valid identifier (1-15 letters, digits, _ or -).");
            }
        }

        // Every airport whose identifier stays the same still holds it
        $holders = [];
        foreach ($currentIdentifiers as $airportId => $identifier) {
            if (! isset($final["airport:{$airportId}"])) {
                $holders[$this->normalise($identifier)][] = "airport:{$airportId}";
            }
        }
        foreach ($final as $key => $identifier) {
            $holders[$identifier][] = $key;
        }

        foreach ($final as $identifier) {
            $keys = $holders[$identifier] ?? [];

            if (count($keys) > 1) {
                $conflicts[] = $this->conflict(
                    'identifier_conflict',
                    $identifier,
                    array_map(fn (string $key) => $this->party($key, $currentIdentifiers, $claims, $changeset), $keys),
                    "More than one airport would end up as {$identifier}. Change the decision on one of them, or give the existing airport a new identifier."
                );
                $holders[$identifier] = []; // report each identifier once
            }
        }

        return [
            'identifier_changes' => $identifierChanges,
            'creates' => $creates,
            'modify' => $modify,
            'untag' => $untag,
            'conflicts' => $conflicts,
            'unresolved' => $unresolved,
        ];
    }

    public function normalise(string $identifier): string
    {
        return strtoupper(trim($identifier));
    }

    private function party(string $key, array $currentIdentifiers, array $claims, array $changeset = []): array
    {
        if (str_starts_with($key, 'op:')) {
            $opId = substr($key, 3);
            $op = collect($changeset['operations'] ?? [])->firstWhere('id', $opId);

            return [
                'kind' => 'create',
                'op_ids' => [$opId],
                'name' => $op['incoming']['name'] ?? null,
            ];
        }

        return $this->airportParty((int) substr($key, 8), $currentIdentifiers, $claims);
    }

    private function airportParty(int $airportId, array $currentIdentifiers, array $claims): array
    {
        return [
            'kind' => 'airport',
            'airport_id' => $airportId,
            'current_identifier' => $currentIdentifiers[$airportId] ?? null,
            'op_ids' => $claims[$airportId] ?? [],
        ];
    }

    private function conflict(string $type, ?string $identifier, array $parties, string $message): array
    {
        return [
            'type' => $type,
            'identifier' => $identifier,
            'parties' => $parties,
            'message' => $message,
        ];
    }
}
