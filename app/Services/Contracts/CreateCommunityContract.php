<?php

namespace App\Services\Contracts;

use App\Models\Airport;
use App\Models\CommunityJobContract;
use App\Models\Enums\CargoType;
use App\Models\Enums\ContractType;
use App\Services\Contracts\Data\CargoData;
use App\Services\Contracts\Data\ContractData;
use Carbon\Carbon;

class CreateCommunityContract
{
    public function __construct(
        protected StoreContracts $storeContracts,
        protected CalcContractValue $calcContractValue
    ) {
    }

    public function execute(CommunityJobContract $job)
    {
        $depAirport = Airport::find($job->dep_airport_id);
        $arrAirport = Airport::find($job->arr_airport_id);

        $cargoType = CargoType::from((int) $job->cargo_type);
        $qty = $cargoType === CargoType::Cargo ? $job->payload : $job->pax;
        $cargo = new CargoData($job->cargo, $cargoType, $qty);

        $distance = $depAirport->distanceTo($arrAirport);
        $heading = $depAirport->bearingTo($arrAirport);
        $value = $this->calcContractValue->execute($cargo->type, $cargo->qty, $distance);

        $contractData = new ContractData(
            departure: $depAirport,
            destination: $arrAirport,
            cargo: $cargo,
            contractType: ContractType::Community,
            expiresAt: Carbon::now()->addDays(7),
            value: $value,
            distance: $distance,
            heading: $heading,
            isShared: true,
        );

        $this->storeContracts->execute([$contractData], ContractType::Community, null, true, $job);
    }
}
