<?php

namespace App\Services\Airports\Sync;

use App\Models\Airport;
use App\Services\Airports\AirportCsvRow;
use Illuminate\Support\Facades\DB;

class ApplyAirportSyncPlan
{
    public function execute(AirportSyncPlan $plan): void
    {
        $flags = $plan->additions ? AirportCsvRow::flagsByCountry() : [];

        DB::transaction(function () use ($plan, $flags) {
            $renamed = array_filter($plan->changes, fn (AirportChange $change) => $change->identifierChanged());

            // Move every changing identifier out of the way first, so renames can be applied in any order
            // without tripping the unique index (eg, swaps or chains of renames)
            foreach ($renamed as $id => $change) {
                Airport::whereKey($id)->update(['identifier' => "~{$id}"]);
            }

            foreach ($renamed as $id => $change) {
                Airport::whereKey($id)->update(['identifier' => $change->identifier] + $this->attributes($change));
            }

            // Everything else is a flag/tag change, many of which will be identical
            $groups = [];
            foreach (array_diff_key($plan->changes, $renamed) as $id => $change) {
                $attributes = $this->attributes($change);
                $groups[json_encode($attributes)]['attributes'] = $attributes;
                $groups[json_encode($attributes)]['ids'][] = $id;
            }

            foreach ($groups as $group) {
                foreach (array_chunk($group['ids'], 1000) as $ids) {
                    Airport::whereKey($ids)->update($group['attributes']);
                }
            }

            $now = now();
            $additions = array_map(fn (SyncRow $row) => array_merge(AirportCsvRow::toAttributes($row->data, $flags), [
                'identifier' => $row->identifier,
                'is_thirdparty' => false,
                'is_hub' => false,
                'closed' => false,
                'sim_type' => json_encode([$plan->sim->value]),
                'created_at' => $now,
                'updated_at' => $now,
            ]), $plan->additions);

            foreach (array_chunk($additions, 500) as $chunk) {
                Airport::insert($chunk);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(AirportChange $change): array
    {
        return [
            'sim_type' => json_encode($change->simTypes),
            'closed' => $change->closed,
            'is_thirdparty' => $change->isThirdParty,
        ];
    }
}
