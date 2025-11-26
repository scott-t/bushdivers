<?php

namespace App\Http\Controllers\Admin\Manufacturers;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateManufacturerController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, $id): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'logo_url' => 'nullable|string|max:255',
        ]);

        $manufacturer = Manufacturer::findOrFail($id);
        $manufacturer->name = $validated['name'];
        $manufacturer->logo_url = $validated['logo_url'] ?? '';
        $manufacturer->save();

        return redirect()->route('admin.manufacturers')->with(['success' => 'Manufacturer updated']);
    }
}
