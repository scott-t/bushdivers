<?php

namespace App\Services\Contracts\Profiles;

class AirportContractProfile
{
    public function __construct(
        public readonly int $minJobs,
        public readonly int $maxJobs,
        public readonly array $rangeBands,
        public readonly array $cargoWeights,
        public readonly int $maxCargoLbs,
        public readonly int $maxPax,
        public readonly array $destSizeBias,
        public readonly bool $guaranteeHub,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            minJobs:      $config['min_jobs'],
            maxJobs:      $config['max_jobs'],
            rangeBands:   $config['range_bands'],
            cargoWeights: $config['cargo_weights'],
            maxCargoLbs:  $config['max_cargo_lbs'],
            maxPax:       $config['max_pax'],
            destSizeBias: $config['dest_size_bias'],
            guaranteeHub: $config['guarantee_hub'],
        );
    }
}
