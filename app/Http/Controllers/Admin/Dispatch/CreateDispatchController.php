<?php

namespace App\Http\Controllers\Admin\Dispatch;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminAddDispatch;
use App\Models\Airport;
use App\Models\CargoType;
use App\Models\Enums\CargoType as CargoTypeEnum;
use App\Models\Enums\ContractType;
use App\Services\Contracts\CalcContractValue;
use App\Services\Contracts\Data\CargoData;
use App\Services\Contracts\Data\ContractData;
use App\Services\Contracts\StoreContracts;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;

class CreateDispatchController extends Controller
{
    protected CalcContractValue $calcContractValue;
    protected StoreContracts $storeContracts;

    public function __construct(CalcContractValue $calcContractValue, StoreContracts $storeContracts)
    {
        $this->calcContractValue = $calcContractValue;
        $this->storeContracts = $storeContracts;
    }


    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(AdminAddDispatch $request): RedirectResponse
    {
        // currently base only airports
        $origin = Airport::where('identifier', $request->source_airport_id)->firstOrFail();
        $destination = Airport::where('identifier', $request->destination_airport_id)->firstOrFail();
        $cargoQty = (int) $request->cargo_qty;

        /** @var CargoType $cargo */
        $cargo = CargoType::where('type', CargoTypeEnum::Cargo)->inRandomOrder()->firstOrFail();

        $distance = $origin->distanceTo($destination);
        $heading = $origin->bearingTo($destination);
        $value = $this->calcContractValue->execute(CargoTypeEnum::Cargo, $cargoQty, $distance);

        $cargoData = new CargoData($cargo->text, CargoTypeEnum::Cargo, $cargoQty);
        $contractData = new ContractData(
            departure: $origin,
            destination: $destination,
            cargo: $cargoData,
            contractType: ContractType::General,
            expiresAt: Carbon::now()->addDays(7),
            value: $value,
            distance: $distance,
            heading: $heading,
        );

        $this->storeContracts->execute([$contractData]);

        return redirect()->back()->with([
            'success' => 'Dispatch created successfully'
        ]);
    }
}
