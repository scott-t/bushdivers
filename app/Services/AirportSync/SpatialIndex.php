<?php

namespace App\Services\AirportSync;

use App\Models\Airport;
use Location\Coordinate;

/**
 * Fixed-size lat/lon grid for "what is within N nm of here" lookups, avoiding
 * an O(N*M) scan of every airport for every incoming row.
 */
class SpatialIndex
{
    private const CELL_DEGREES = 0.05;

    private const LON_CELLS = 7200; // 360 / CELL_DEGREES

    /** @var array<string, array<int, array{id:int, lat:float, lon:float}>> */
    private array $cells = [];

    public function add(int $id, float $lat, float $lon): void
    {
        $this->cells[$this->key($this->latCell($lat), $this->lonCell($lon))][] = [
            'id' => $id,
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    /**
     * @return array<int, float> id => distance (nm), sorted nearest first
     */
    public function within(float $lat, float $lon, float $radiusNm): array
    {
        $origin = new Coordinate($lat, $lon);
        $radiusDegrees = $radiusNm / 60;

        $latSpan = (int) ceil($radiusDegrees / self::CELL_DEGREES);
        $cosLat = max(cos(deg2rad(min(abs($lat) + $radiusDegrees, 90))), 0.01);
        $lonSpan = min((int) ceil($radiusDegrees / $cosLat / self::CELL_DEGREES), self::LON_CELLS / 2);

        $latCell = $this->latCell($lat);
        $lonCell = $this->lonCell($lon);

        $results = [];

        for ($dLat = -$latSpan; $dLat <= $latSpan; $dLat++) {
            for ($dLon = -$lonSpan; $dLon <= $lonSpan; $dLon++) {
                $wrappedLon = (($lonCell + $dLon) % self::LON_CELLS + self::LON_CELLS) % self::LON_CELLS;

                foreach ($this->cells[$this->key($latCell + $dLat, $wrappedLon)] ?? [] as $point) {
                    $distance = Airport::distanceBetween($origin, new Coordinate($point['lat'], $point['lon']));

                    if ($distance <= $radiusNm) {
                        $results[$point['id']] = $distance;
                    }
                }
            }
        }

        asort($results);

        return $results;
    }

    private function latCell(float $lat): int
    {
        return (int) floor(($lat + 90) / self::CELL_DEGREES);
    }

    private function lonCell(float $lon): int
    {
        return ((int) floor(($lon + 180) / self::CELL_DEGREES)) % self::LON_CELLS;
    }

    private function key(int $latCell, int $lonCell): string
    {
        return $latCell . ':' . $lonCell;
    }
}
