<?php

namespace App\Http\Controllers\MarketPlace;

use App\Http\Controllers\Controller;
use App\Models\Fleet;
use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowNewMarketPlaceController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $buyer): Response
    {
        // Get all fleet aircraft that are available in marketplace
        if ($buyer == 'admin') {
            $fleet = Fleet::with('manufacturer')
                ->where('company_fleet', true)
                ->where('manufacturer_id', '>', 0)
                ->orderBy('name')
                ->get();
        } else {
            $fleet = Fleet::with('manufacturer')
                ->where('manufacturer_id', '>', 0)
                ->orderBy('name')
                ->get();
        }

        // Get all manufacturers for filter
        $manufacturers = Manufacturer::orderBy('name')->get();

        // Get unique values for filters
        $sizes = Fleet::where('manufacturer_id', '>', 0)->distinct()->pluck('size')->filter()->sort()->values();
        $types = Fleet::where('manufacturer_id', '>', 0)->distinct()->pluck('type')->filter()->sort()->values();

        return Inertia::render('Marketplace/NewMarketplace', [
            'fleet' => $fleet,
            'manufacturers' => $manufacturers,
            'sizes' => $sizes,
            'types' => $types,
            'buyer' => $buyer
        ]);
    }
}
