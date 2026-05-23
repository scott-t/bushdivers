<?php

namespace App\Services\Contracts;

use App\Models\Enums\CargoType;
use App\Services\Contracts\Data\CargoData;
use App\Services\Contracts\Profiles\AirportContractProfile;
use Illuminate\Support\Facades\DB;

class GenerateContractCargo
{
    public function execute(AirportContractProfile $profile): CargoData
    {
        $cargoType = $this->weightedPick($profile->cargoWeights) === 'cargo'
            ? CargoType::Cargo
            : CargoType::Passenger;

        if ($cargoType === CargoType::Cargo) {
            $cargo = DB::table('cargo_types')
                ->where('type', CargoType::Cargo->value)
                ->where(function ($q) use ($profile) {
                    $q->whereNull('min_cargo_split')
                      ->orWhere('min_cargo_split', '<=', $profile->maxCargoLbs);
                })
                ->inRandomOrder()
                ->first();

            // Fall back to passenger if no cargo type fits
            if (!$cargo) {
                $cargoType = CargoType::Passenger;
            }
        }

        if ($cargoType === CargoType::Passenger) {
            $cargo = DB::table('cargo_types')
                ->where('type', CargoType::Passenger->value)
                ->inRandomOrder()
                ->first();

            if (!$cargo) {
                return new CargoData('Passengers', CargoType::Passenger, random_int(1, $profile->maxPax));
            }

            $step = max(1, (int) ($cargo->min_cargo_split ?? 1));
            $qty = random_int(1, max(1, $profile->maxPax));
            if ($step > 1) {
                $qty = (int) (round($qty / $step) * $step);
                $qty = max($qty, $step);
            }
            return new CargoData($cargo->text, CargoType::Passenger, $qty);
        }

        $step = max(1, (int) ($cargo->min_cargo_split ?? 1));
        $qty = random_int(max(1, $step), max($step, $profile->maxCargoLbs));
        if ($step > 1) {
            $qty = (int) (round($qty / $step) * $step);
            $qty = max($qty, $step);
        }

        return new CargoData($cargo->text, CargoType::Cargo, $qty);
    }

    private function weightedPick(array $weights): mixed
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_key_first($weights);
        }
        $rand = random_int(1, $total);
        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $key;
            }
        }
        return array_key_last($weights);
    }
}
