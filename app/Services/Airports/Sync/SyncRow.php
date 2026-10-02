<?php

namespace App\Services\Airports\Sync;

/**
 * A validated airport row from a sim airport CSV
 */
final readonly class SyncRow
{
    /**
     * @param array<string, mixed> $data Validated CSV fields
     */
    public function __construct(
        public int $row,
        public string $identifier,
        public string $name,
        public float $lat,
        public float $lon,
        public array $data = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data Validated CSV fields
     */
    public static function fromCsv(int $row, array $data): self
    {
        return new self(
            row: $row,
            identifier: strtoupper(trim($data['identifier'])),
            name: (string) $data['name'],
            lat: (float) $data['lat'],
            lon: (float) $data['lon'],
            data: $data,
        );
    }
}
