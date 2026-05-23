<?php

namespace App\Services\Contracts\Data;

use App\Models\Enums\CargoType;

readonly class CargoData
{
    public function __construct(
        public string $name,
        public CargoType $type,
        public int $qty,
    ) {
    }
}
