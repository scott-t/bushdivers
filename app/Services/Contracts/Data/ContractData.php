<?php

namespace App\Services\Contracts\Data;

use App\Models\Airport;
use App\Models\Enums\ContractType;
use Carbon\Carbon;

readonly class ContractData
{
    public function __construct(
        public Airport $departure,
        public Airport $destination,
        public CargoData $cargo,
        public ContractType $contractType,
        public Carbon $expiresAt,
        public float $value,
        public float $distance,
        public float $heading,
        public ?int $requiredAircraftId = null,
        public bool $isShared = false,
    ) {
    }
}
