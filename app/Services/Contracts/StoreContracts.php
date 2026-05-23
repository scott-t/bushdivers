<?php

namespace App\Services\Contracts;

use App\Models\Airport;
use App\Models\CommunityJobContract;
use App\Models\Contract;
use App\Models\Enums\CargoType;
use App\Models\Enums\ContractType;
use App\Models\User;
use App\Services\Contracts\Data\ContractData;

class StoreContracts
{
    public function execute(
        array $contracts,
        ContractType $type = ContractType::General,
        ?User $user = null,
        bool $isShared = false,
        ?CommunityJobContract $communityJob = null
    ): void {
        foreach ($contracts as $contractInfo) {
            if (!$contractInfo) {
                continue;
            }

            if ($contractInfo instanceof ContractData) {
                $this->storeFromDto($contractInfo, $type, $user, $isShared, $communityJob);
            } else {
                $this->storeFromArray($contractInfo, $type, $user, $isShared, $communityJob);
            }
        }
    }

    private function storeFromDto(ContractData $data, ContractType $type, ?User $user, bool $isShared, ?CommunityJobContract $communityJob): void
    {
        $contract = new Contract();
        $contract->contract_type_id = $data->contractType !== ContractType::General ? $data->contractType : $type;
        $contract->dep_airport_id = $data->departure->id;
        $contract->current_airport_id = $data->departure->id;
        $contract->arr_airport_id = $data->destination->id;
        $contract->distance = $data->distance;
        $contract->contract_value = $data->value;
        $contract->cargo_type = $data->cargo->type;
        $contract->cargo = $data->cargo->name;
        $contract->cargo_qty = $data->cargo->qty;
        $contract->heading = $data->heading;
        $contract->expires_at = $data->expiresAt;
        $contract->is_available = true;
        $contract->is_shared = $data->isShared || $isShared;
        $contract->community_job_contract_id = $communityJob->id ?? null;

        if ($data->cargo->type === CargoType::Cargo) {
            $contract->payload = $data->cargo->qty;
        } else {
            $contract->pax = $data->cargo->qty;
        }

        if ($user) {
            $contract->user_id = $user->id;
        }

        $contract->save();
    }

    private function storeFromArray(array $contractInfo, ContractType $type, ?User $user, bool $isShared, ?CommunityJobContract $communityJob): void
    {
        $depAirport = Airport::where('identifier', $contractInfo['departure'])->first();
        $arrAirport = Airport::where('identifier', $contractInfo['destination'])->first();

        if (!$depAirport || !$arrAirport) {
            return;
        }

        $contract = new Contract();
        $contract->contract_type_id = $type;
        $contract->dep_airport_id = $depAirport->id;
        $contract->current_airport_id = $depAirport->id;
        $contract->arr_airport_id = $arrAirport->id;
        $contract->distance = $contractInfo['distance'];
        $contract->contract_value = $contractInfo['contract_value'];
        $contract->cargo_type = $contractInfo['cargo_type'];
        $contract->cargo = $contractInfo['cargo'];
        $contract->cargo_qty = $contractInfo['cargo_qty'];
        $contract->heading = $contractInfo['heading'];
        $contract->expires_at = $contractInfo['expires_at'];
        $contract->is_available = true;
        $contract->is_shared = $isShared;
        $contract->community_job_contract_id = $communityJob->id ?? null;

        if ($contractInfo['cargo_type'] == CargoType::Cargo || $contractInfo['cargo_type'] == CargoType::Cargo->value) {
            $contract->payload = $contractInfo['cargo_qty'];
        } else {
            $contract->pax = $contractInfo['cargo_qty'];
        }

        if ($user) {
            $contract->user_id = $user->id;
            if (!empty($contractInfo['is_custom'])) {
                $contract->is_custom = true;
            }
        }

        if (!empty($contractInfo['is_fuel'])) {
            $contract->user_id = $user->id ?? null;
            $contract->is_fuel = true;
            $contract->fuel_qty = $contractInfo['fuel_qty'];
            $contract->fuel_type = $contractInfo['fuel_type'];
            $contract->is_available = false;
        }

        $contract->save();
    }
}

