<?php

namespace App\Services\AirportSync;

use App\Models\Enums\PirepState;
use Illuminate\Support\Facades\DB;

class AirportReferenceCounter
{
    /**
     * Live (non-historical) usages of each airport. Anything referenced here should not be
     * silently untagged, as it would strand aircraft, pilots, contracts or tours.
     *
     * @param  array<int, int>  $airportIds
     * @return array<int, int> airport id => reference count (only airports with references)
     */
    public function liveReferences(array $airportIds): array
    {
        $counts = [];

        foreach (array_chunk(array_values(array_unique($airportIds)), 1000) as $ids) {
            $sources = [
                DB::table('aircraft')->whereIn('current_airport_id', $ids)->selectRaw('current_airport_id as airport_id, count(*) as c')->groupBy('current_airport_id'),
                DB::table('aircraft')->whereIn('hub_id', $ids)->selectRaw('hub_id as airport_id, count(*) as c')->groupBy('hub_id'),
                DB::table('rentals')->where('is_active', true)->whereIn('current_airport_id', $ids)->selectRaw('current_airport_id as airport_id, count(*) as c')->groupBy('current_airport_id'),
                DB::table('users')->whereIn('current_airport_id', $ids)->selectRaw('current_airport_id as airport_id, count(*) as c')->groupBy('current_airport_id'),
                DB::table('fleets')->whereIn('hq_airport_id', $ids)->selectRaw('hq_airport_id as airport_id, count(*) as c')->groupBy('hq_airport_id'),
                DB::table('tours')->whereIn('start_airport_id', $ids)->selectRaw('start_airport_id as airport_id, count(*) as c')->groupBy('start_airport_id'),
                DB::table('tour_checkpoints')->whereIn('checkpoint_airport_id', $ids)->selectRaw('checkpoint_airport_id as airport_id, count(*) as c')->groupBy('checkpoint_airport_id'),
            ];

            foreach (['dep_airport_id', 'arr_airport_id', 'current_airport_id'] as $column) {
                $sources[] = DB::table('contracts')->where('is_completed', false)->whereIn($column, $ids)->selectRaw("{$column} as airport_id, count(*) as c")->groupBy($column);
            }

            foreach ($sources as $query) {
                foreach ($query->get() as $row) {
                    $counts[(int) $row->airport_id] = ($counts[(int) $row->airport_id] ?? 0) + (int) $row->c;
                }
            }
        }

        return $counts;
    }

    /**
     * Airports with an in-flight pirep. The tracker refers to these by identifier, so they must not be renamed.
     *
     * @param  array<int, int>  $airportIds
     * @return array<int, true>
     */
    public function withActivePireps(array $airportIds): array
    {
        $locked = [];

        foreach (array_chunk(array_values(array_unique($airportIds)), 1000) as $ids) {
            foreach (['departure_airport_id', 'arrival_airport_id'] as $column) {
                DB::table('pireps')
                    ->whereIn('state', [PirepState::DISPATCH, PirepState::IN_PROGRESS])
                    ->whereIn($column, $ids)
                    ->distinct()
                    ->pluck($column)
                    ->each(function ($id) use (&$locked) {
                        $locked[(int) $id] = true;
                    });
            }
        }

        return $locked;
    }
}
