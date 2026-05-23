<?php

namespace App\Services\Contracts\Profiles;

use App\Models\Airport;

class AirportProfileFactory
{
    public function fromAirport(Airport $airport): AirportContractProfile
    {
        $size = $airport->size ?? 2;
        $baseConfig = config('contract_profiles.' . $size, config('contract_profiles.2'));

        if ($airport->is_hub) {
            $overlay = config('contract_profiles.hub_overlay', []);
            $baseConfig = array_merge($baseConfig, $overlay);
        }

        return AirportContractProfile::fromArray($baseConfig);
    }
}
