<?php

namespace App\Services\Contracts;

use App\Models\Airport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateContractDetails
{
    public function __construct(
        protected GenerateContractCargo $generateContractCargo,
        protected CalcContractValue $calcContractValue
    ) {
    }

    /**
     * Summary of execute
     * @param Airport $origin
     * @param Airport $destination
     * @param null|array{name: string, qty: int, type: \App\Models\Enums\CargoType, min_cargo_split: int} $preDefinedCargo
     * @return null|array{cargo: mixed, cargo_qty: int, cargo_type: \App\Models\Enums\CargoType, contract_value: float, departure: mixed, destination: mixed, distance: float, expires_at: Carbon, heading: int, id: string, is_fuel: bool, min_cargo_split: int}
     */
    public function execute(Airport $origin, Airport $destination, ?array $preDefinedCargo = null): null|array
    {
        try {
            //$contracts = [];
            if ($preDefinedCargo) {
                $cargo = $preDefinedCargo;
            } else {
                $cargo = $this->generateContractCargo->execute();
            }

            // get distance and heading
            $distance = $origin->distanceTo($destination);
            $heading = $origin->bearingTo($destination);
            $expiry = Carbon::now()->addDays(rand(1, 8));
            $expiryMultiplier = match (true) {
                $expiry > Carbon::now()->addDays(5) && $expiry < Carbon::now()->addDays(7) => 1.2,
                $expiry > Carbon::now()->addDays(3) && $expiry < Carbon::now()->addDays(5) => 1.5,
                $expiry > Carbon::now()->addDays(1) && $expiry < Carbon::now()->addDays(3) => 1.8,
                $expiry < Carbon::now()->addHours(24) => 2.0,
                default => 1.0,
            };

            $contractValue = $this->calcContractValue->execute($cargo['type'], $cargo['qty'], $distance);
            $contractValue = $contractValue * $expiryMultiplier;
            // create contract
            $contract = [
                'id' => $origin->identifier.'-'.$destination->identifier,
                'departure' => $origin->identifier,
                'destination' => $destination->identifier,
                'min_cargo_split' => $cargo['min_cargo_split'],
                'cargo' => $cargo['name'],
                'cargo_type' => $cargo['type'],
                'cargo_qty' => $cargo['qty'],
                'distance' => $distance,
                'heading' => $heading,
                'contract_value' => $contractValue,
                'expires_at' => $expiry,
                'is_fuel' => false
            ];
            return $contract;

        } catch (\Exception $e) {
            Log::channel('single')->debug($e->getMessage(), ['where' => 'Contract details generation']);
        }

        return null;
    }
}
