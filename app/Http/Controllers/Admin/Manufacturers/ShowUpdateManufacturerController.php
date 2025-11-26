<?php

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowUpdateManufacturerController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id): Response
    {
        $manufacturer = Manufacturer::findOrFail($id);

        return Inertia::render('Admin/ManufacturerEdit', ['manufacturer' => $manufacturer]);
    }
}
