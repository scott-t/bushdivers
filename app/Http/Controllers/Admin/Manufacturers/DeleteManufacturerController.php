<?php

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeleteManufacturerController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id): RedirectResponse
    {
        $manufacturer = Manufacturer::findOrFail($id);
        $manufacturer->delete();

        return redirect()->route('admin.manufacturers')->with(['success' => 'Manufacturer deleted']);
    }
}
