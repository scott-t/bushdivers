<?php

namespace App\Http\Controllers\Admin\Airports;

use App\Http\Controllers\Controller;
use App\Traits\HandlesBulkUpload;
use App\Models\Airport;
use App\Services\Airports\AirportCsvRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Location\Coordinate;

/**
 * @phpstan-import-type CsvResult from \App\Services\CsvBulkUploadService
 */
class BulkUploadAirportsController extends Controller
{
    use HandlesBulkUpload;

    public function __construct()
    {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        return $this->handleBulkUpload(
            $request,
            $this->processAirportsFile(...),
            '/admin/airports'
        );
    }

    /**
     * @return CsvResult
     */
    private function processAirportsFile(UploadedFile $file, Request $request): array
    {
        // Shared caches that will persist across all row validations
        $sharedState = (object) [
            'identifierCache' => collect(),
            'coordinateCache' => collect(),
        ];

        return $this->getCsvService()->processFile(
            $file,
            function ($record, $rowNumber, $context) use ($sharedState) {
                // Create validation rules with access to the current record
                $validationRules = [
                    'identifier' => [
                        'required',
                        'string',
                        'max:5',
                        'alpha_num',
                        function ($attribute, $value, $fail) use ($sharedState) {
                            $upperValue = strtoupper($value);

                            // Check if already used in this file
                            if ($sharedState->identifierCache->has($upperValue)) {
                                $fail("The identifier {$upperValue} is used multiple times in this file.");

                                return;
                            }

                            // Check if exists in database
                            if (Airport::where('identifier', $upperValue)->exists()) {
                                $fail("The airport identifier {$upperValue} already exists.");

                                return;
                            }

                            $sharedState->identifierCache->put($upperValue, true);
                        },
                    ],
                ] + AirportCsvRow::rules();

                $validationRules['lat'][] = function ($attribute, $value, $fail) use ($sharedState, $record) {
                    $lon = $record['lon'] ?? null;
                    if (! $lon) {
                        return;
                    }

                    try {
                        $coordKey = "{$value},{$lon}";
                        $currentCoord = new Coordinate((float)$value, (float)$lon);

                        // Check if coordinates already used in this file
                        if ($sharedState->coordinateCache->has($coordKey)) {
                            $fail("These coordinates ({$value}, {$lon}) are used multiple times in this file.");

                            return;
                        }

                        // Check if coordinates are too close to other coordinates in this upload file
                        foreach ($sharedState->coordinateCache as $existingCoord) {
                            $distance =  \App\Models\Concerns\HasLocation::distanceBetween($currentCoord, $existingCoord);
                            if ($distance < 2) {
                                $fail("Coordinates ({$value}, {$lon}) are within 2nm of another airport in this upload file at ({$existingCoord->getLat()}, {$existingCoord->getLng()}).");
                                return;
                            }
                        }

                        // Check if coordinates are too close to existing airports
                        if ($nearAirport = Airport::whereNull('user_id')->inRangeOf($currentCoord, 0, 2)->first()) {
                            $fail("ICAO {$nearAirport->identifier} already exists within 2nm of coordinates ({$value}, {$lon}).");
                            return;
                        }

                        $sharedState->coordinateCache->put($coordKey, $currentCoord);
                    } catch (\Exception $e) {
                        // If coordinate creation fails, let Laravel's between validation handle it
                        return;
                    }
                };

                $validator = Validator::make($record, $validationRules);

                if ($validator->fails()) {
                    return [
                        'success' => false,
                        'errors' => [[
                            'row' => $rowNumber,
                            'message' => implode(', ', $validator->errors()->all()),
                        ]],
                    ];
                }

                try {
                    // Process the validated data
                    $this->createAirport($validator->validated());

                    return ['success' => true];
                } catch (\Exception $e) {
                    return [
                        'success' => false,
                        'errors' => [[
                            'row' => $rowNumber,
                            'message' => $e->getMessage(),
                        ]],
                    ];
                }
            },
            [],
            AirportCsvRow::REQUIRED_COLUMNS
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAirport(array $data): void
    {
        Airport::create(array_merge(AirportCsvRow::toAttributes($data), [
            'is_thirdparty' => true,
        ]));
    }
}
