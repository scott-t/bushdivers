<?php

namespace App\Services\AirportSync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AirportSyncExecutor
{
    /** @var array<string, string|null> */
    private array $flagCache = [];

    public function __construct(
        private readonly IdentifierResolver $resolver,
        private readonly AirportReferenceCounter $references,
        private readonly AirportSyncService $syncService,
    ) {
    }

    /**
     * Apply a reviewed changeset. Everything happens in one transaction; any drift since analysis
     * or any remaining conflict aborts without writing. There is no built-in undo: take a DB backup first.
     *
     * @return array<string, int> counts per action
     *
     * @throws AirportSyncConflictException
     */
    public function execute(array $changeset, bool $includeUntags = false): array
    {
        $simType = SimType::from($changeset['sim_type']);

        return DB::transaction(function () use ($changeset, $simType, $includeUntags) {
            $touchedIds = $this->syncService->touchedAirportIds($changeset);

            /** @var Collection<int, Airport> $airports */
            $airports = Airport::query()->whereIn('id', $touchedIds)->lockForUpdate()->get()->keyBy('id');

            $plan = $this->resolver->plan(
                $changeset,
                $this->syncService->currentIdentifiers(),
                $this->references->withActivePireps($touchedIds),
                $includeUntags
            );

            if ($plan['unresolved'] > 0) {
                throw new AirportSyncConflictException("{$plan['unresolved']} review item(s) still need a decision.");
            }

            if ($plan['conflicts']) {
                throw new AirportSyncConflictException('The sync has unresolved conflicts.', $plan['conflicts']);
            }

            $drift = $this->detectDrift($changeset, $plan, $airports);

            if ($drift) {
                throw new AirportSyncConflictException('Airports have changed since the analysis ran. Upload the file again.', $drift);
            }

            $opsById = collect($changeset['operations'])->keyBy('id');

            $summary = [
                'updated' => 0,
                'renamed' => count($plan['identifier_changes']),
                'promoted' => 0,
                'created' => 0,
                'untagged' => 0,
                'ignored' => $opsById->where('decision', SyncOp::IGNORE)->count(),
            ];

            // 1. Untag airports this sim no longer has (no identifier changes)
            foreach ($plan['untag'] as $airportId => $opId) {
                $airport = $airports[$airportId];
                $airport->sim_type = $this->simTypeValues($airport)->reject(fn (string $value) => $value === $simType->value)->values()->all();
                $airport->save();
                $summary['untagged']++;
            }

            // 2. Move every changing identifier out of the way (~{id}), so swaps and chains can't hit the unique index
            foreach (array_keys($plan['identifier_changes']) as $airportId) {
                DB::table('airports')->where('id', $airportId)->update(['identifier' => '~' . $airportId]);
            }

            // 3. Final identifiers and incoming data
            foreach ($plan['modify'] as $airportId => $modification) {
                $airport = $airports[$airportId];
                $op = $opsById[$modification['op_id']];

                if ($modification['promote']) {
                    $airport->user_id = null;
                    $airport->is_thirdparty = false;
                    $summary['promoted']++;
                } else {
                    $summary['updated']++;
                }

                $this->applyIncoming($airport, $op['incoming'], $simType);
                $airport->identifier = $plan['identifier_changes'][$airportId] ?? $airport->identifier;
                $airport->save();
            }

            // Airports whose identifier was only changed by an admin override
            foreach ($plan['identifier_changes'] as $airportId => $identifier) {
                if (! isset($plan['modify'][$airportId])) {
                    $airport = $airports[$airportId];
                    $airport->identifier = $identifier;
                    $airport->save();
                }
            }

            // 4. New airports last, once every identifier they might need has been freed
            foreach ($plan['creates'] as $opId => $identifier) {
                $this->createFromIncoming($opsById[$opId]['incoming'], $identifier, $simType);
                $summary['created']++;
            }

            return $summary;
        });
    }

    /**
     * Compare every airport the plan writes to against the snapshot taken at analysis.
     */
    private function detectDrift(array $changeset, array $plan, Collection $airports): array
    {
        $snapshots = [];

        foreach ($changeset['operations'] as $op) {
            foreach (array_filter([$op['airport'] ?? null, ...($op['candidates'] ?? [])]) as $snapshot) {
                $snapshots[(int) $snapshot['id']] = $snapshot;
            }
        }

        $drift = [];

        foreach (array_unique([...array_keys($plan['modify']), ...array_keys($plan['untag']), ...array_keys($plan['identifier_changes'])]) as $airportId) {
            $airport = $airports[$airportId] ?? null;
            $snapshot = $snapshots[$airportId] ?? null;

            if (! $airport) {
                $drift[] = ['type' => 'airport_missing', 'message' => "Airport #{$airportId} no longer exists."];
                continue;
            }

            if ($snapshot && (
                $airport->getRawOriginal('identifier') !== $snapshot['identifier']
                || (string) $airport->getRawOriginal('updated_at') !== (string) $snapshot['updated_at']
            )) {
                $drift[] = ['type' => 'airport_changed', 'message' => "{$snapshot['identifier']} was edited after the analysis ran."];
            }
        }

        return $drift;
    }

    private function applyIncoming(Airport $airport, array $incoming, SimType $simType): void
    {
        foreach ([
            'name', 'location', 'country', 'country_code', 'lat', 'lon', 'altitude', 'magnetic_variance',
            'longest_runway_length', 'longest_runway_surface', 'has_avgas', 'has_jetfuel', 'size',
        ] as $column) {
            // Missing values in the export never blank out what we already have
            if (($incoming[$column] ?? null) !== null) {
                $airport->{$column} = $incoming[$column];
            }
        }

        if (! empty($incoming['country_code'])) {
            $airport->flag = $this->resolveFlagForCountryCode((string) $incoming['country_code']) ?? $airport->flag;
        }

        $airport->sim_type = $this->simTypeValues($airport)->push($simType->value)->unique()->values()->all();
    }

    private function createFromIncoming(array $incoming, string $identifier, SimType $simType): Airport
    {
        // is_hub / user_id aren't fillable, and is_hub has no column default
        $airport = (new Airport())->forceFill([
            'identifier' => $identifier,
            'name' => $incoming['name'] ?? $identifier,
            'location' => $incoming['location'] ?? null,
            'country' => $incoming['country'] ?? null,
            'country_code' => $incoming['country_code'] ?? null,
            'flag' => ! empty($incoming['country_code']) ? $this->resolveFlagForCountryCode((string) $incoming['country_code']) : null,
            'lat' => $incoming['lat'],
            'lon' => $incoming['lon'],
            'magnetic_variance' => $incoming['magnetic_variance'] ?? 0,
            'altitude' => $incoming['altitude'] ?? null,
            'size' => $incoming['size'] ?? null,
            'longest_runway_length' => $incoming['longest_runway_length'] ?? null,
            'longest_runway_surface' => $incoming['longest_runway_surface'] ?? null,
            'has_avgas' => (bool) ($incoming['has_avgas'] ?? false),
            'has_jetfuel' => (bool) ($incoming['has_jetfuel'] ?? false),
            'is_hub' => false,
            'is_thirdparty' => false,
            'user_id' => null,
            'sim_type' => [$simType->value],
        ]);
        $airport->save();

        return $airport;
    }

    private function simTypeValues(Airport $airport): Collection
    {
        return collect($airport->sim_type ?? [])
            ->map(fn ($value) => $value instanceof SimType ? $value->value : (string) $value)
            ->filter();
    }

    private function resolveFlagForCountryCode(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));

        if ($countryCode === '') {
            return null;
        }

        if (array_key_exists($countryCode, $this->flagCache)) {
            return $this->flagCache[$countryCode];
        }

        return $this->flagCache[$countryCode] = Airport::where('country_code', $countryCode)->whereNotNull('flag')->value('flag');
    }
}
