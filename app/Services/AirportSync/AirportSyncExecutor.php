<?php

namespace App\Services\AirportSync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AirportSyncExecutor
{
    public function execute(array $results, SimType $simType, bool $includeDeactivations = false): array
    {
        return DB::transaction(function () use ($results, $simType, $includeDeactivations) {
            $summary = [
                'auto_updated' => 0,
                'new_airports' => 0,
                'renamed' => 0,
                'promoted' => 0,
                'ignored' => 0,
                'deactivated' => 0,
            ];

            foreach ($results['auto_updates'] ?? [] as $item) {
                $airport = Airport::find($item['matched_id']);

                if (! $airport) {
                    continue;
                }

                $this->applyIncomingToAirport($airport, $item['incoming'], $simType);
                $summary['auto_updated']++;
            }

            foreach ($results['new_airports'] ?? [] as $item) {
                $this->createAirportFromIncoming($item['incoming'] ?? [], $simType);
                $summary['new_airports']++;
            }

            foreach ($results['review_items'] ?? [] as $item) {
                $decision = $item['admin_decision'] ?? null;

                if (! $decision) {
                    continue;
                }

                if ($decision === 'ignore') {
                    $summary['ignored']++;
                    continue;
                }

                if ($decision === 'new') {
                    $this->createAirportFromIncoming($item['incoming'] ?? [], $simType);
                    $summary['new_airports']++;
                    continue;
                }

                $candidateId = $item['candidate']['id'] ?? null;
                $airport = $candidateId ? Airport::find($candidateId) : null;

                if (! $airport) {
                    continue;
                }

                if ($decision === 'rename') {
                    $this->applyIncomingToAirport($airport, $item['incoming'] ?? [], $simType);
                    $summary['renamed']++;
                    continue;
                }

                if ($decision === 'promote') {
                    $airport->user_id = null;
                    $airport->is_thirdparty = false;
                    $this->applyIncomingToAirport($airport, $item['incoming'] ?? [], $simType);
                    $summary['promoted']++;
                }
            }

            if ($includeDeactivations) {
                foreach ($results['deactivations'] ?? [] as $item) {
                    $airport = Airport::find($item['id'] ?? null);

                    if (! $airport) {
                        continue;
                    }

                    $current = $this->airportSimTypeValues($airport)
                        ->reject(fn (string $value) => $value === $simType->value)
                        ->values();

                    $airport->sim_type = $current->all();
                    $airport->save();

                    $summary['deactivated']++;
                }
            }

            return $summary;
        });
    }

    private function applyIncomingToAirport(Airport $airport, array $incoming, SimType $simType): void
    {
        $airport->identifier = strtoupper((string) ($incoming['identifier'] ?? $airport->identifier));
        $airport->name = $incoming['name'] ?? $airport->name;
        $airport->location = $incoming['location'] ?? $airport->location;
        $airport->country = $incoming['country'] ?? $airport->country;
        $airport->country_code = $incoming['country_code'] ?? $airport->country_code;
        $airport->lat = $incoming['lat'] ?? $airport->lat;
        $airport->lon = $incoming['lon'] ?? $airport->lon;
        $airport->altitude = $incoming['altitude'] ?? $airport->altitude;
        $airport->magnetic_variance = $incoming['magnetic_variance'] ?? $airport->magnetic_variance;
        $airport->longest_runway_length = $incoming['longest_runway_length'] ?? $airport->longest_runway_length;
        $airport->longest_runway_surface = $incoming['longest_runway_surface'] ?? $airport->longest_runway_surface;
        $airport->has_avgas = $incoming['has_avgas'] ?? $airport->has_avgas;
        $airport->has_jetfuel = $incoming['has_jetfuel'] ?? $airport->has_jetfuel;
        $airport->size = $incoming['size'] ?? $airport->size;

        if (! empty($incoming['country_code'])) {
            $airport->flag = Airport::where('country_code', $incoming['country_code'])->first()?->flag;
        }

        $simTypes = $this->airportSimTypeValues($airport)
            ->push($simType->value)
            ->unique()
            ->values();

        $airport->sim_type = $simTypes->all();
        $airport->save();
    }

    private function createAirportFromIncoming(array $incoming, SimType $simType): void
    {
        Airport::create([
            'identifier' => strtoupper((string) ($incoming['identifier'] ?? '')),
            'name' => $incoming['name'] ?? '',
            'location' => $incoming['location'] ?? null,
            'country' => $incoming['country'] ?? null,
            'country_code' => $incoming['country_code'] ?? null,
            'flag' => ! empty($incoming['country_code']) ? Airport::where('country_code', $incoming['country_code'])->first()?->flag : null,
            'lat' => $incoming['lat'] ?? 0,
            'lon' => $incoming['lon'] ?? 0,
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
    }

    private function airportSimTypeValues(Airport $airport): Collection
    {
        return collect($airport->sim_type ?? [])
            ->map(fn ($value) => $value instanceof SimType ? $value->value : (string) $value)
            ->filter();
    }
}
