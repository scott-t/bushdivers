<?php

namespace App\Services\Airports\Sync;

use App\Models\Airport;
use App\Services\Airports\AirportCsvRow;
use Illuminate\Support\Facades\DB;

class ApplyAirportSyncPlan
{
    public function execute(AirportSyncPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
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

            foreach ($plan->additions as $row) {
                (new Airport())->forceFill(array_merge(AirportCsvRow::toAttributes($row->data), [
                    'identifier' => $row->identifier,
                    'is_thirdparty' => false,
                    'is_hub' => false,
                    'closed' => false,
                    'sim_type' => [$plan->sim],
                ]))->save();
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
