<?php

namespace App\Http\Controllers\Scheduler\Pireps;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Services\General\LogSchedule;
use App\Services\Pireps\FindInactivePireps;
use App\Services\Pireps\RemoveSinglePirep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CleanPirepsController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request, FindInactivePireps $findInactivePireps, RemoveSinglePirep $removeSinglePirep, LogSchedule $logSchedule): JsonResponse
    {
        try {
            $inactive = $findInactivePireps->execute();
            foreach ($inactive as $pirep) {
                $removeSinglePirep->execute($pirep);
            }
            $logSchedule->execute('inactive-pireps', true);
            return response()->json(['message' => 'Successfully processed pirep cleanse']);
        } catch (\Exception $exception) {
            $logSchedule->execute('inactive-pireps', false);
            return response()->json(['message' => $exception->getMessage()], 500);
        }
    }
}
