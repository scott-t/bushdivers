<?php

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowManufacturerListController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request): Response
    {
        $manufacturers = Manufacturer::orderBy('name')->get();

        return Inertia::render('Admin/ManufacturerList', ['manufacturers' => $manufacturers]);
    }
}
