<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelCargoRequest;
use App\Services\Contracts\CreateFuelContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CreateFuelCargoContractController extends Controller
{
    protected CreateFuelContract $createFuelContract;
    public function __construct(CreateFuelContract $createCustomRoute)
    {
        $this->createFuelContract = $createCustomRoute;
    }

    public function __invoke(FuelCargoRequest $request): RedirectResponse
    {
        try {
            $this->createFuelContract->execute(Auth::user()->current_airport_id, strtoupper($request->destination), $request->qty, $request->fuel_type, $request->weight, Auth::user()->id);
            return redirect()->back()->with(['success' => 'Fuel cargo contract created']);
        } catch (ModelNotFoundException $exception) {
            return redirect()->back()->with(['error' => 'Airport not found']);
        }
    }
}
