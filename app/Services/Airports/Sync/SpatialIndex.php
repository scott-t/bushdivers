<?php

namespace App\Services\Airports\Sync;

use App\Models\Airport;
use Location\Coordinate;

/**
 * In-memory grid index for finding points within a short distance of each other,
 * without a range query per point.
 *
 * @template T
 */
class SpatialIndex
{
    // ~2nm of latitude per cell
    private const float CELL_DEGREES = 1 / 30;

    private const int LON_CELLS = 360 * 30;

    /**
     * @var array<int, array<int, list<array{0: float, 1: float, 2: T}>>>
     */
    private array $cells = [];

    /**
     * @param T $item
     */
    public function add(float $lat, float $lon, mixed $item): void
    {
        // Coordinates are created on demand, as holding tens of thousands of them uses a lot of memory
        $this->cells[$this->latCell($lat)][$this->lonCell($lon)][] = [$lat, $lon, $item];
    }

    /**
     * Find all items within the given distance, nearest first
     *
     * @return list<array{item: T, distance: float}>
     */
    public function near(float $lat, float $lon, float $maxNm): array
    {
        $origin = new Coordinate($lat, $lon);

        $latSpan = (int) ceil($maxNm / 60 / self::CELL_DEGREES);

        // Longitude cells narrow towards the poles, so widen the search using the most poleward latitude in range
        $poleward = min(89.9, abs($lat) + $maxNm / 60);
        $lonSpan = (int) ceil($maxNm / (60 * cos(deg2rad($poleward))) / self::CELL_DEGREES);

        $latCell = $this->latCell($lat);
        $lonCell = $this->lonCell($lon);

        if ($lonSpan * 2 + 1 >= self::LON_CELLS) {
            $lonCells = range(0, self::LON_CELLS - 1);
        } else {
            // Wrap around the antimeridian
            $lonCells = array_map(
                fn ($dx) => (($lonCell + $dx) % self::LON_CELLS + self::LON_CELLS) % self::LON_CELLS,
                range(-$lonSpan, $lonSpan)
            );
        }

        $results = [];
        for ($y = $latCell - $latSpan; $y <= $latCell + $latSpan; $y++) {
            if (!isset($this->cells[$y])) {
                continue;
            }

            foreach ($lonCells as $x) {
                foreach ($this->cells[$y][$x] ?? [] as [$entryLat, $entryLon, $item]) {
                    $distance = Airport::distanceBetween($origin, new Coordinate($entryLat, $entryLon));
                    if ($distance <= $maxNm) {
                        $results[] = ['item' => $item, 'distance' => $distance];
                    }
                }
            }
        }

        usort($results, fn ($a, $b) => $a['distance'] <=> $b['distance']);

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
}
