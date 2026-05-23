<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Models\Contract;
use App\Services\Contracts\ContractGenerationOrchestrator;
use App\Services\Contracts\GenerationMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FindContractsController extends Controller
{
    protected ContractGenerationOrchestrator $orchestrator;

    public function __construct(ContractGenerationOrchestrator $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request): Response
    {
        $airport = Airport::where('identifier', $request->icao)->first();
        if (!$airport) {
            return Inertia::render('Contracts/Contracts')->with(['error' => 'Airport not found']);
        }

        // Ensure contracts are topped up for this airport
        $this->orchestrator->execute($airport, GenerationMode::Outbound, Auth::user());

        $user = Auth::user();
        $contracts = Contract::with(['depAirport', 'arrAirport'])
            ->whereHas('arrAirport', fn ($q) => $q->forUser($user))
            ->where('dep_airport_id', $airport->id)
            ->where('is_available', true)
            ->whereRaw('expires_at >= Now()')
            ->orderBy('distance')
            ->get();

        return Inertia::render('Contracts/Contracts', ['searchedContracts' => $contracts, 'airport' => $airport]);
    }
}
