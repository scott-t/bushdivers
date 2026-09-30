<?php

namespace App\Services\AirportSync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Location\Coordinate;

/**
 * Builds a changeset reconciling a LittleNavMap export against the airports table.
 *
 * Location decides identity: an airport row stays tied to the physical airfield (all
 * foreign keys are by id), and its identifier is just an attribute that may change.
 * Matching runs in priority passes, each only seeing rows that are still unassigned:
 *   1. identifier + within 1nm             -> update (automatic); no operation at all if nothing would change,
 *                                             but the airport still counts as present so pass 6 won't untag it
 *   2. single mutual neighbour within 0.5nm -> rename / promote (review, pre-filled)
 *      several neighbours within 0.5nm     -> ambiguous (review)
 *   3. nearest neighbour within 2nm         -> rename / promote (review)
 *   4. identifier match further than 1nm   -> relocate (review), or create if that airport was paired elsewhere
 *   5. anything left incoming              -> create (automatic)
 *   6. anything left in the DB for the sim -> untag (automatic), or untag_review if it is a hub / in use
 * Nothing is ever deleted; identifier collisions are reported by IdentifierResolver.
 */
class AirportSyncService
{
    public const SAME_AIRPORT_NM = 1.0;

    public const STRONG_MATCH_NM = 0.5;

    public const WEAK_MATCH_NM = 2.0;

    private const PROGRESS_EVERY = 250;

    /** Airport columns populated from the import, used to skip matches that would change nothing */
    private const COMPARED_COLUMNS = [
        'name', 'location', 'country', 'country_code', 'lat', 'lon', 'altitude', 'magnetic_variance',
        'longest_runway_length', 'longest_runway_surface', 'has_avgas', 'has_jetfuel', 'size',
    ];

    public function __construct(
        private readonly IdentifierResolver $resolver,
        private readonly AirportReferenceCounter $references,
    ) {
    }

    public function analyse(Collection $records, SimType $simType, ?callable $progressCallback = null): array
    {
        [$incoming, $invalidRows, $mergedDuplicates] = $this->normaliseRecords($records);

        [$airports, $stored] = $this->loadAirports();
        $index = new SpatialIndex();
        $idByIdentifier = [];

        foreach ($airports as $id => $airport) {
            $index->add($id, $airport['lat'], $airport['lon']);
            $idByIdentifier[strtoupper($airport['identifier'])] = $id;
        }

        $incomingIdentifiers = array_flip(array_column($incoming, 'identifier'));
        $total = count($incoming);

        // Candidate edges for every incoming row
        $edges = [];
        foreach ($incoming as $i => $row) {
            $near = $index->within($row['lat'], $row['lon'], self::WEAK_MATCH_NM);
            $identId = $idByIdentifier[$row['identifier']] ?? null;

            $edges[$i] = [
                'near' => $near,
                'ident_id' => $identId,
                'ident_distance' => $identId === null ? null : ($near[$identId] ?? Airport::distanceBetween(
                    new Coordinate($row['lat'], $row['lon']),
                    new Coordinate($airports[$identId]['lat'], $airports[$identId]['lon'])
                )),
            ];

            if ($progressCallback && (($i + 1) % self::PROGRESS_EVERY === 0 || $i + 1 === $total)) {
                $progressCallback($i + 1, $total);
            }
        }

        $operations = [];
        $assigned = [];   // incoming index => true
        $claimed = [];    // airport id => op id
        $reserved = [];   // airport id => true (candidate of an ambiguous op)
        $unchanged = 0;

        // Pass 1: same identifier, same place
        foreach ($edges as $i => $edge) {
            $airportId = $edge['ident_id'];

            if ($airportId === null || $edge['ident_distance'] > self::SAME_AIRPORT_NM || isset($claimed[$airportId])) {
                continue;
            }

            $assigned[$i] = true;
            $changes = $this->changedColumns(
                $stored[$airportId],
                $incoming[$i],
                in_array($simType->value, $airports[$airportId]['sim_types'], true)
            );

            // Nothing to write, so no operation - but it is still claimed, which is what keeps it out of pass 6 (untag)
            if (! $changes) {
                $claimed[$airportId] = 'unchanged';
                $unchanged++;
                continue;
            }

            $operations[] = $op = $this->op(SyncOp::UPDATE, [
                'confidence' => 'high',
                'airport' => $airports[$airportId],
                'incoming' => $incoming[$i],
                'distance_nm' => $edge['ident_distance'],
                'changes' => $changes,
            ]);
            $claimed[$airportId] = $op['id'];
        }

        // Pass 2: strong spatial matches (identifier differs)
        $strong = [];
        $claimants = [];
        foreach ($edges as $i => $edge) {
            if (isset($assigned[$i])) {
                continue;
            }

            foreach ($edge['near'] as $airportId => $distance) {
                if ($distance <= self::STRONG_MATCH_NM && ! isset($claimed[$airportId])) {
                    $strong[$i][$airportId] = $distance;
                    $claimants[$airportId][] = $i;
                }
            }
        }

        foreach ($strong as $i => $neighbours) {
            if (count($neighbours) === 1 && count($claimants[array_key_first($neighbours)]) === 1) {
                $airportId = array_key_first($neighbours);
                $operations[] = $op = $this->spatialOp($airports[$airportId], $incoming[$i], $neighbours[$airportId], 'high', $incomingIdentifiers);
                $claimed[$airportId] = $op['id'];
            } else {
                $operations[] = $this->op(SyncOp::AMBIGUOUS, [
                    'confidence' => 'low',
                    'incoming' => $incoming[$i],
                    'candidates' => collect($neighbours)
                        ->map(fn (float $distance, int $airportId) => $airports[$airportId] + ['distance_nm' => $distance])
                        ->values()
                        ->all(),
                    'distance_nm' => reset($neighbours),
                    'note' => 'Several existing airports are within ' . self::STRONG_MATCH_NM . 'nm. Pick the one this is, or add it as new.',
                ]);

                foreach (array_keys($neighbours) as $airportId) {
                    $reserved[$airportId] = true;
                }
            }

            $assigned[$i] = true;
        }

        // Pass 3: weak spatial matches, nearest pairs first
        $weak = [];
        foreach ($edges as $i => $edge) {
            if (isset($assigned[$i])) {
                continue;
            }

            foreach ($edge['near'] as $airportId => $distance) {
                if (! isset($claimed[$airportId]) && ! isset($reserved[$airportId])) {
                    $weak[] = [$i, $airportId, $distance];
                }
            }
        }

        usort($weak, fn (array $a, array $b) => $a[2] <=> $b[2]);

        foreach ($weak as [$i, $airportId, $distance]) {
            if (isset($assigned[$i]) || isset($claimed[$airportId])) {
                continue;
            }

            $operations[] = $op = $this->spatialOp($airports[$airportId], $incoming[$i], $distance, 'low', $incomingIdentifiers);
            $claimed[$airportId] = $op['id'];
            $assigned[$i] = true;
        }

        // Pass 4: identifier matches that are far away
        foreach ($edges as $i => $edge) {
            if (isset($assigned[$i]) || $edge['ident_id'] === null) {
                continue;
            }

            $airportId = $edge['ident_id'];

            if (isset($claimed[$airportId]) || isset($reserved[$airportId])) {
                // That airport is really somewhere else; this identifier is (probably) being vacated
                $operations[] = $this->op(SyncOp::CREATE, [
                    'incoming' => $incoming[$i],
                    'note' => "{$airports[$airportId]['identifier']} currently belongs to an airport {$edge['ident_distance']}nm away that was matched to a different incoming airport.",
                ]);
            } else {
                $operations[] = $op = $this->op(SyncOp::RELOCATE, [
                    'confidence' => 'low',
                    'airport' => $airports[$airportId],
                    'incoming' => $incoming[$i],
                    'distance_nm' => $edge['ident_distance'],
                    'note' => "Same identifier but {$edge['ident_distance']}nm apart. Apply moves the existing airport (and everything attached to it).",
                ]);
                $claimed[$airportId] = $op['id'];
            }

            $assigned[$i] = true;
        }

        // Pass 5: nothing matched
        foreach ($incoming as $i => $row) {
            if (! isset($assigned[$i])) {
                $operations[] = $this->op(SyncOp::CREATE, ['incoming' => $row]);
            }
        }

        // Pass 6: airports this sim no longer has
        $missing = collect($airports)->filter(fn (array $airport) => in_array($simType->value, $airport['sim_types'], true)
            && ! $airport['is_thirdparty']
            && ! isset($claimed[$airport['id']])
            && ! isset($reserved[$airport['id']]));

        $referenced = $this->references->liveReferences(array_merge(
            $missing->keys()->all(),
            array_keys(array_filter($claimed, fn (string $opId) => $opId !== 'unchanged'))
        ));

        foreach ($missing as $airportId => $airport) {
            $references = $referenced[$airportId] ?? 0;
            $guarded = $airport['is_hub'] || $references > 0;

            $operations[] = $this->op($guarded ? SyncOp::UNTAG_REVIEW : SyncOp::UNTAG, [
                // Removing a hub / in-use airport from a sim is opt-in
                'decision' => $guarded ? SyncOp::IGNORE : SyncOp::APPLY,
                'airport' => $airport,
                'references' => $references,
                'note' => $guarded ? ($airport['is_hub'] ? 'Hub airport. ' : '') . "{$references} live reference(s) (aircraft, pilots, contracts, tours, fleet HQs)." : null,
            ]);
        }

        foreach ($operations as &$op) {
            if ($op['airport'] && isset($referenced[$op['airport']['id']])) {
                $op['references'] = $referenced[$op['airport']['id']];
            }
        }
        unset($op);

        return $this->refresh([
            'sim_type' => $simType->value,
            'analysed_at' => now()->toIso8601String(),
            'operations' => $operations,
            'unchanged' => $unchanged,
            'identifier_overrides' => [],
            'conflicts' => [],
            'invalid_rows' => $invalidRows,
            'merged_duplicates' => $mergedDuplicates,
            'summary' => [],
        ]);
    }

    /**
     * Recompute conflicts and the summary against the live identifiers. Call after any decision change.
     */
    public function refresh(array $changeset): array
    {
        $plan = $this->resolver->plan(
            $changeset,
            $this->currentIdentifiers(),
            $this->references->withActivePireps($this->touchedAirportIds($changeset))
        );

        $changeset['conflicts'] = $plan['conflicts'];
        $changeset['summary'] = [
            'total_incoming' => collect($changeset['operations'])->whereNotNull('incoming')->count() + ($changeset['unchanged'] ?? 0),
            'unchanged' => $changeset['unchanged'] ?? 0,
            'by_type' => collect($changeset['operations'])->countBy('type')->all(),
            'review_items' => collect($changeset['operations'])->where('requires_review', true)->count(),
            'unresolved' => $plan['unresolved'],
            'conflicts' => count($plan['conflicts']),
            'invalid_rows' => count($changeset['invalid_rows'] ?? []),
            'planned' => [
                'updates' => count($plan['modify']),
                'identifier_changes' => count($plan['identifier_changes']),
                'creates' => count($plan['creates']),
                'untags' => count($plan['untag']),
            ],
        ];

        return $changeset;
    }

    /** @return array<int, string> */
    public function currentIdentifiers(): array
    {
        return Airport::query()->toBase()->pluck('identifier', 'id')->all();
    }

    /** @return array<int, int> */
    public function touchedAirportIds(array $changeset): array
    {
        $ids = array_map('intval', array_keys($changeset['identifier_overrides'] ?? []));

        foreach ($changeset['operations'] ?? [] as $op) {
            if ($op['airport']) {
                $ids[] = (int) $op['airport']['id'];
            }

            foreach ($op['candidates'] ?? [] as $candidate) {
                $ids[] = (int) $candidate['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Drop rows without usable coordinates, and collapse duplicate identifiers that are the same place.
     * Duplicates that are far apart are kept so the resolver reports them as a conflict.
     */
    private function normaliseRecords(Collection $records): array
    {
        $valid = [];
        $invalid = [];

        foreach ($records->values() as $row) {
            $lat = $row['lat'] ?? null;
            $lon = $row['lon'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lon) || abs($lat) > 90 || abs($lon) > 180) {
                $invalid[] = ['identifier' => $row['identifier'] ?? null, 'reason' => 'Missing or invalid coordinates'];
                continue;
            }

            $row['identifier'] = strtoupper(trim((string) $row['identifier']));
            $row['lat'] = (float) $lat;
            $row['lon'] = (float) $lon;
            $valid[] = $row;
        }

        $result = [];
        $merged = [];

        foreach (collect($valid)->groupBy('identifier') as $identifier => $group) {
            if ($group->count() === 1) {
                $result[] = $group->first();
                continue;
            }

            $best = $group->sortByDesc(fn (array $row) => $row['size'] ?? -1)->first();
            $bestCoordinate = new Coordinate($best['lat'], $best['lon']);
            $samePlace = $group->every(fn (array $row) => Airport::distanceBetween($bestCoordinate, new Coordinate($row['lat'], $row['lon'])) <= self::SAME_AIRPORT_NM);

            if ($samePlace) {
                $result[] = $best;
                $merged[] = ['identifier' => $identifier, 'count' => $group->count()];
            } else {
                array_push($result, ...$group->all());
            }
        }

        return [$result, $invalid, $merged];
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array>} airport id => snapshot, airport id => stored COMPARED_COLUMNS
     */
    private function loadAirports(): array
    {
        $airports = [];
        $stored = [];

        Airport::query()
            ->toBase()
            ->select(array_unique(['id', 'identifier', 'name', 'lat', 'lon', 'is_hub', 'is_thirdparty', 'sim_type', 'updated_at', ...self::COMPARED_COLUMNS]))
            ->orderBy('id')
            ->chunkById(2000, function (Collection $chunk) use (&$airports, &$stored) {
                foreach ($chunk as $row) {
                    $airports[(int) $row->id] = $this->airportSnapshot($row);
                    $stored[(int) $row->id] = array_intersect_key((array) $row, array_flip(self::COMPARED_COLUMNS));
                }
            });

        return [$airports, $stored];
    }

    /**
     * Columns the import would change on a matched airport. Mirrors AirportSyncExecutor::applyIncoming:
     * values missing from the export never overwrite what is stored, so they can't count as a change.
     *
     * @return array<int, string>
     */
    private function changedColumns(array $stored, array $incoming, bool $hasSimTag): array
    {
        $changed = $hasSimTag ? [] : ['sim_type'];

        foreach (self::COMPARED_COLUMNS as $column) {
            $new = $incoming[$column] ?? null;
            $old = $stored[$column] ?? null;

            if ($new === null) {
                continue;
            }

            $same = match ($column) {
                // decimal(11,5) columns
                'lat', 'lon', 'magnetic_variance' => $old !== null && round((float) $old, 5) === round((float) $new, 5),
                'altitude', 'longest_runway_length', 'size' => $old !== null && (int) $old === (int) $new,
                'has_avgas', 'has_jetfuel' => (bool) $old === (bool) $new,
                default => (string) $old === (string) $new,
            };

            if (! $same) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    private function airportSnapshot(object $row): array
    {
        $simTypes = is_string($row->sim_type) ? (json_decode($row->sim_type, true) ?? []) : [];

        return [
            'id' => (int) $row->id,
            'identifier' => $row->identifier,
            'name' => $row->name,
            'lat' => (float) $row->lat,
            'lon' => (float) $row->lon,
            'is_hub' => (bool) $row->is_hub,
            'is_thirdparty' => (bool) $row->is_thirdparty,
            'sim_types' => array_values(array_filter($simTypes)),
            'updated_at' => $row->updated_at ? (string) $row->updated_at : null,
        ];
    }

    private function spatialOp(array $airport, array $incoming, float $distance, string $confidence, array $incomingIdentifiers): array
    {
        // Same identifier 1-2nm away: the airport has moved a little
        if (strtoupper($airport['identifier']) === $incoming['identifier']) {
            return $this->op(SyncOp::RELOCATE, [
                'confidence' => 'low',
                'airport' => $airport,
                'incoming' => $incoming,
                'distance_nm' => $distance,
            ]);
        }

        // The existing identifier is still used elsewhere in the import (e.g. a swap) - always ask
        $stillInSim = isset($incomingIdentifiers[strtoupper($airport['identifier'])]);

        if ($stillInSim) {
            $confidence = 'low';
        }

        return $this->op($airport['is_thirdparty'] ? SyncOp::PROMOTE : SyncOp::RENAME, [
            'confidence' => $confidence,
            // Pre-fill only confident renames; promotions take an airport off a user, so always ask
            'decision' => $confidence === 'high' && ! $airport['is_thirdparty'] ? SyncOp::APPLY : null,
            'airport' => $airport,
            'incoming' => $incoming,
            'distance_nm' => $distance,
            'note' => $stillInSim ? "{$airport['identifier']} also appears elsewhere in this import." : null,
        ]);
    }

    private function op(string $type, array $attributes): array
    {
        $automatic = in_array($type, [SyncOp::UPDATE, SyncOp::CREATE, SyncOp::UNTAG], true);

        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'requires_review' => ! $automatic,
            'confidence' => null,
            'decision' => $automatic ? SyncOp::APPLY : null,
            'candidate_id' => null,
            'airport' => null,
            'candidates' => [],
            'incoming' => null,
            'distance_nm' => null,
            'references' => 0,
            'changes' => [],
            'note' => null,
        ], $attributes);
    }
}
