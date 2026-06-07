<?php

namespace App\Services\AirportSync;

use App\Models\Airport;
use App\Models\Concerns\HasLocation;
use App\Models\Enums\SimType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Location\Coordinate;

class AirportSyncService
{
    public function analyse(Collection $records, SimType $simType, ?callable $progressCallback = null): array
    {
        $airports = $this->loadAirports();
        $airportsByIdentifier = $airports->keyBy(fn (Airport $airport) => strtoupper($airport->identifier));

        $autoUpdates = [];
        $newAirports = [];
        $reviewItems = [];
        $matchedAirportIds = [];

        $total = $records->count();

        if ($progressCallback) {
            $progressCallback(0, $total);
        }

        foreach ($records->values() as $index => $incoming) {
            $coordinate = new Coordinate((float) $incoming['lat'], (float) $incoming['lon']);
            $identifier = strtoupper((string) $incoming['identifier']);

            $exactMatch = $airportsByIdentifier->get($identifier);

            if ($exactMatch) {
                $distance = HasLocation::distanceBetween($coordinate, $exactMatch->getCoordinate());
                $matchedAirportIds[$exactMatch->id] = true;

                if ($distance <= 1.0) {
                    $autoUpdates[] = [
                        'incoming' => $incoming,
                        'matched_id' => $exactMatch->id,
                        'matched_identifier' => $exactMatch->identifier,
                    ];
                } else {
                    $reviewItems[] = [
                        'id' => (string) Str::uuid(),
                        'type' => 'possible_swap',
                        'confidence' => 'low',
                        'incoming' => $incoming,
                        'candidate' => $this->candidatePayload($exactMatch),
                        'candidates' => [],
                        'distance_nm' => (float) $distance,
                        'admin_decision' => null,
                    ];
                }

                if ($progressCallback) {
                    $progressCallback($index + 1, $total);
                }

                continue;
            }

            $nearbyCandidates = $this->findNearbyCandidates($coordinate, $airports);
            $withinHalfNm = $nearbyCandidates->filter(fn (array $item) => $item['distance_nm'] <= 0.5)->values();

            if ($withinHalfNm->count() === 1) {
                $candidate = $withinHalfNm->first();
                $matchedAirportIds[$candidate['airport']->id] = true;

                $reviewItems[] = [
                    'id' => (string) Str::uuid(),
                    'type' => $candidate['airport']->is_thirdparty ? 'promote_thirdparty' : 'possible_rename',
                    'confidence' => 'high',
                    'incoming' => $incoming,
                    'candidate' => $this->candidatePayload($candidate['airport']),
                    'candidates' => [],
                    'distance_nm' => (float) $candidate['distance_nm'],
                    'admin_decision' => null,
                ];
            } elseif ($withinHalfNm->count() > 1) {
                foreach ($withinHalfNm as $match) {
                    $matchedAirportIds[$match['airport']->id] = true;
                }

                $reviewItems[] = [
                    'id' => (string) Str::uuid(),
                    'type' => 'ambiguous_proximity',
                    'confidence' => 'low',
                    'incoming' => $incoming,
                    'candidate' => $this->candidatePayload($withinHalfNm->first()['airport']),
                    'candidates' => $withinHalfNm->map(fn (array $match) => $this->candidatePayload($match['airport']))->values()->all(),
                    'distance_nm' => (float) $withinHalfNm->first()['distance_nm'],
                    'admin_decision' => null,
                ];
            } else {
                $betweenHalfAndTwoNm = $nearbyCandidates
                    ->filter(fn (array $item) => $item['distance_nm'] > 0.5 && $item['distance_nm'] <= 2.0)
                    ->values();

                if ($betweenHalfAndTwoNm->isNotEmpty()) {
                    $candidate = $betweenHalfAndTwoNm->first();
                    $matchedAirportIds[$candidate['airport']->id] = true;

                    $reviewItems[] = [
                        'id' => (string) Str::uuid(),
                        'type' => 'possible_rename',
                        'confidence' => 'low',
                        'incoming' => $incoming,
                        'candidate' => $this->candidatePayload($candidate['airport']),
                        'candidates' => [],
                        'distance_nm' => (float) $candidate['distance_nm'],
                        'admin_decision' => null,
                    ];
                } else {
                    $newAirports[] = ['incoming' => $incoming];
                }
            }

            if ($progressCallback) {
                $progressCallback($index + 1, $total);
            }
        }

        $deactivations = $airports
            ->filter(fn (Airport $airport) => $this->airportHasSimType($airport, $simType))
            ->reject(fn (Airport $airport) => isset($matchedAirportIds[$airport->id]))
            ->map(function (Airport $airport) {
                return [
                    'id' => $airport->id,
                    'identifier' => $airport->identifier,
                    'name' => $airport->name,
                    'current_sim_types' => $this->airportSimTypeValues($airport)->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'sim_type' => $simType->value,
            'summary' => [
                'total_incoming' => $records->count(),
                'auto_updates' => count($autoUpdates),
                'new_airports' => count($newAirports),
                'review_items' => count($reviewItems),
                'deactivations' => count($deactivations),
            ],
            'auto_updates' => $autoUpdates,
            'new_airports' => $newAirports,
            'review_items' => $reviewItems,
            'deactivations' => $deactivations,
        ];
    }

    private function loadAirports(): Collection
    {
        $airports = collect();

        $query = Airport::query();

        if (Schema::hasColumn('airports', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->orderBy('id')->chunkById(500, function (Collection $chunk) use (&$airports) {
            $airports = $airports->concat($chunk->values());
        });

        return $airports->values();
    }

    private function findNearbyCandidates(Coordinate $incomingCoordinate, Collection $airports): Collection
    {
        return $airports
            ->map(function (Airport $airport) use ($incomingCoordinate) {
                $distance = HasLocation::distanceBetween($incomingCoordinate, $airport->getCoordinate());

                return [
                    'airport' => $airport,
                    'distance_nm' => (float) $distance,
                ];
            })
            ->filter(fn (array $candidate) => $candidate['distance_nm'] <= 2.0)
            ->sortBy('distance_nm')
            ->values();
    }

    private function candidatePayload(Airport $airport): array
    {
        return [
            'id' => $airport->id,
            'identifier' => $airport->identifier,
            'lat' => (float) $airport->lat,
            'lon' => (float) $airport->lon,
            'name' => $airport->name,
            'is_thirdparty' => (bool) $airport->is_thirdparty,
        ];
    }

    private function airportHasSimType(Airport $airport, SimType $simType): bool
    {
        return $this->airportSimTypeValues($airport)->contains($simType->value);
    }

    private function airportSimTypeValues(Airport $airport): Collection
    {
        return collect($airport->sim_type ?? [])
            ->map(fn ($value) => $value instanceof SimType ? $value->value : (string) $value)
            ->filter();
    }
}
