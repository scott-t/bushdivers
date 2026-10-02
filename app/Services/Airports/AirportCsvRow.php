<?php

namespace App\Services\Airports;

use App\Models\Airport;
use App\Models\Enums\AirportRunwaySurface;

/**
 * Shared column definitions, validation and conversion for airport CSV files
 * (third-party bulk upload and sim airport sync).
 */
class AirportCsvRow
{
    /**
     * @var array<string>
     */
    public const array REQUIRED_COLUMNS = [
        'identifier',
        'name',
        'location',
        'country',
        'country_code',
        'lat',
        'lon',
        'magnetic_variance',
        'altitude',
        'size',
        'longest_runway_length',
        'longest_runway_width',
        'longest_runway_surface',
        'has_avgas',
        'has_jetfuel',
    ];

    /**
     * Per-field validation rules, excluding the identifier (callers define their own constraints)
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'max:2'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'magnetic_variance' => ['required', 'numeric', 'between:-180,180'],
            'altitude' => ['required', 'numeric', 'min:-1500', 'max:30000'],
            'size' => ['required', 'integer', 'min:0', 'max:5'],
            'longest_runway_length' => ['required', 'integer', 'min:0', 'max:40000'],
            'longest_runway_width' => ['required', 'integer', 'min:0', 'max:2000'],
            'longest_runway_surface' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $validNames = collect(AirportRunwaySurface::cases())->map(fn ($case) => $case->name)->toArray();

                    if (! in_array(strtoupper($value), $validNames)) {
                        $validSurfacesList = implode(', ', $validNames);
                        $fail("The runway surface must be one of: {$validSurfacesList}. Got: {$value}");
                    }
                },
            ],
            'has_avgas' => ['required', 'boolstring'],
            'has_jetfuel' => ['required', 'boolstring'],
        ];
    }

    /**
     * Convert a validated CSV row into airport model attributes
     *
     * @param array<string, mixed> $data
     * @param array<string, string|null>|null $flags Preloaded flags by country code (see flagsByCountry()), otherwise looked up per row
     * @return array<string, mixed>
     */
    public static function toAttributes(array $data, ?array $flags = null): array
    {
        $attributes = array_merge($data, [
            'identifier' => strtoupper($data['identifier']),
            'altitude' => (int) $data['altitude'],
            'magnetic_variance' => $data['magnetic_variance'] ?? 0,
            'longest_runway_surface' => self::convertRunwaySurfaceToValue($data['longest_runway_surface']),
            'has_avgas' => strtolower($data['has_avgas']) == 'true',
            'has_jetfuel' => strtolower($data['has_jetfuel']) == 'true',
        ]);

        // Set flag from existing airport with same country code
        if (! empty($data['country_code'])) {
            $attributes['flag'] = $flags !== null
                ? $flags[$data['country_code']] ?? null
                : Airport::where('country_code', $data['country_code'])->first()?->flag;
        }

        return $attributes;
    }

    /**
     * Flag of the first airport in each country, for converting many rows without a lookup per row
     *
     * @return array<string, string|null>
     */
    public static function flagsByCountry(): array
    {
        return Airport::query()
            ->whereIn('id', Airport::query()->selectRaw('MIN(id)')->whereNotNull('country_code')->groupBy('country_code'))
            ->pluck('flag', 'country_code')
            ->all();
    }

    private static function convertRunwaySurfaceToValue(string $surface): string
    {
        $upperSurface = strtoupper($surface);

        // Check if it's an enum name (case-insensitive)
        foreach (AirportRunwaySurface::cases() as $case) {
            if ($case->name === $upperSurface) {
                return $case->value;
            }
        }

        // Fallback - return as is (shouldn't happen if validation passed)
        return $surface;
    }
}
