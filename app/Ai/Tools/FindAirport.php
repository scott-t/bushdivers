<?php

namespace App\Ai\Tools;

use App\Models\Airport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindAirport implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Find an airport based on the provided parameters.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $origin = $request['origin'] ?? null;

        if ($origin)
            $origin = Airport::query()->where('identifier', $origin)->firstOrFail();

        $airport = Airport::query()
            ->forUser(Auth::user())
            ->inRangeOf($origin ?? Auth::user(), floatval($request['min_distance'] ?? 30), floatval($request['max_distance']))
            ->when($request['heading'] ?? null, fn ($query, $heading) => $query->inBearingFrom($origin ?? Auth::user(), floatval($heading)))
            ->when($request['fuel'] ?? false, fn ($query) => $query->fuel())
            ->when($request['min_size'] ?? null, fn ($query, $minSize) => $query->where('size', '>=', $minSize))
            ->when($request['runway_length'] ?? null, fn ($query, $runwayLength) => $query->where('longest_runway_length', '>=', $runwayLength))
            ->inRandomOrder()
            ->limit(min($request['limit'] ?? 3, 5))
            ->get()
            ->map(fn (Airport $airport) => [
                'name' => $airport->name,
                'identifier' => $airport->identifier,
                'location' => $airport->location,
                'country' => $airport->country,
                'size' => $airport->size,
                'altitude' => $airport->altitude,
                'runway_length' => $airport->longest_runway_length,
                'has_fuel' => $airport->has_avgas || $airport->has_jetfuel,
                'distance' => $airport->distance,
                'bearing' => $origin ? $origin->bearingTo($airport) : null,

            ]);

        return $airport->isNotEmpty() ? $airport->toJson() : 'No airport found';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'origin' => $schema->string()->description('The origin airport ICAO. User current location if not specified.'),
            'min_distance' => $schema->number()->description('The minimum distance in nautical miles. Default to 30'),
            'max_distance' => $schema->number()->required()->description('The maximum distance in nautical miles.'),
            'heading' => $schema->number()->description('The heading in degrees. Search for airports within 45 degrees of this heading.'),
            'min_size' => $schema->number()->min(0)->max(5)->description('The minimum size of the airport. '),
            'fuel' => $schema->boolean()->description('Whether the airport must have fuel available.'),
            'runway_length' => $schema->number()->description('The minimum runway length in feet.'),
            'limit' => $schema->number()->min(0)->default(3)->max(5)->description('The maximum number of airports to return. Default to 3, max 5.'),
        ];
    }
}
