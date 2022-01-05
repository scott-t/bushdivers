<?php

namespace App\Http\Controllers\Fleet;

use App\Models\Fleet;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ShowFleetAircraftController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request): Response
    {
        $fleet = Fleet::with(['aircraft' => function ($q) {
            $q->where('is_rental', false);
            $q->where('owner_id', 0);
            $q->orderBy('hub_id');
        }])
            ->where('company_fleet', true)
            ->orderBy('type')
            ->get();
        return Inertia::render('Fleet/FleetDetails', ['fleet' => $fleet]);
    }
}