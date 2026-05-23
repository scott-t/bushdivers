<?php

namespace App\Services\Contracts;

use App\Models\Airport;
use App\Models\Contract;
use App\Models\Enums\ContractType;
use App\Models\User;
use App\Services\Contracts\Data\ContractData;
use App\Services\Contracts\Profiles\AirportContractProfile;
use App\Services\Contracts\Profiles\AirportProfileFactory;
use Carbon\Carbon;

enum GenerationMode
{
    case Outbound;
    case Inbound;
}

class ContractGenerationOrchestrator
{
    public function __construct(
        protected AirportProfileFactory $profileFactory,
        protected GenerateContractCargo $generateCargo,
        protected CalcContractValue $calcValue,
        protected StoreContracts $storeContracts,
    ) {
    }

    public function execute(Airport $airport, GenerationMode $mode, ?User $user = null): void
    {
        if ($mode === GenerationMode::Outbound) {
            $this->handleOutbound($airport, $user);
        } else {
            $this->handleInbound($airport, $user);
        }
    }

    private function handleOutbound(Airport $airport, ?User $user): void
    {
        $profile = $this->profileFactory->fromAirport($airport);

        $currentCount = Contract::where('dep_airport_id', $airport->id)
            ->where('is_available', true)
            ->where('is_completed', false)
            ->count();

        $numToGenerate = max(0, $profile->maxJobs - $currentCount);
        if ($numToGenerate === 0) {
            return;
        }

        $contracts = $this->generateOutbound($airport, $profile, $numToGenerate, $user);
        if (!empty($contracts)) {
            $this->storeContracts->execute($contracts);
        }
    }

    private function handleInbound(Airport $airport, ?User $user): void
    {
        $inboundCount = Contract::where('arr_airport_id', $airport->id)
            ->where('is_available', true)
            ->where('is_completed', false)
            ->count();

        $threshold = $this->inboundThreshold($airport);
        if ($inboundCount >= $threshold) {
            return;
        }

        $sources = Airport::base($user)
            ->inRangeOf($airport, 2, 250)
            ->where('size', '>=', $airport->size)
            ->inRandomOrder()
            ->limit(5)
            ->get();

        foreach ($sources as $source) {
            $sourceProfile = $this->profileFactory->fromAirport($source);
            $sourceCount = Contract::where('dep_airport_id', $source->id)
                ->where('is_available', true)
                ->where('is_completed', false)
                ->count();

            if ($sourceCount < $sourceProfile->minJobs) {
                $numToGenerate = max(0, $sourceProfile->maxJobs - $sourceCount);
                if ($numToGenerate > 0) {
                    $contracts = $this->generateOutbound($source, $sourceProfile, $numToGenerate, $user);
                    if (!empty($contracts)) {
                        $this->storeContracts->execute($contracts);
                    }
                }
            }
        }
    }

    private function inboundThreshold(Airport $airport): int
    {
        if ($airport->is_hub) return 10;
        if ($airport->size >= 4) return 8;
        if ($airport->size >= 2) return 4;
        return 0;
    }

    private function generateOutbound(Airport $origin, AirportContractProfile $profile, int $count, ?User $user): array
    {
        $contracts = [];
        $hubDestinationsGenerated = 0;

        foreach ($profile->rangeBands as $band) {
            $bandCount = (int) floor($count * $band['weight'] / 100);
            for ($i = 0; $i < $bandCount; $i++) {
                $destination = $this->pickDestination($origin, $band, $profile, $user);
                if ($destination && $destination->id !== $origin->id) {
                    if ($destination->is_hub) {
                        $hubDestinationsGenerated++;
                    }
                    $contracts[] = $this->buildContractData($origin, $destination, $profile);
                }
            }
        }

        // guarantee_hub: ensure at least one contract to a hub if none were naturally generated
        if ($profile->guaranteeHub && $hubDestinationsGenerated === 0) {
            $hub = Airport::base($user)
                ->inRangeOf($origin, 2, 650)
                ->hub()
                ->inRandomOrder()
                ->first();

            if ($hub && $hub->id !== $origin->id) {
                $contracts[] = $this->buildContractData($origin, $hub, $profile);
            }
        }

        return $contracts;
    }

    private function pickDestination(Airport $origin, array $band, AirportContractProfile $profile, ?User $user): ?Airport
    {
        $targetSize = $this->weightedPick($profile->destSizeBias);

        $destination = Airport::base($user)
            ->inRangeOf($origin, $band['min'], $band['max'])
            ->where('size', $targetSize)
            ->inRandomOrder()
            ->first();

        if (!$destination) {
            $destination = Airport::base($user)
                ->inRangeOf($origin, $band['min'], $band['max'])
                ->whereBetween('size', [max(0, $targetSize - 1), min(5, $targetSize + 1)])
                ->inRandomOrder()
                ->first();
        }

        if (!$destination) {
            $destination = Airport::base($user)
                ->inRangeOf($origin, $band['min'], $band['max'])
                ->inRandomOrder()
                ->first();
        }

        return $destination;
    }

    private function buildContractData(Airport $origin, Airport $destination, AirportContractProfile $profile): ContractData
    {
        $cargo = $this->generateCargo->execute($profile);
        $distance = $origin->distanceTo($destination);
        $heading = $origin->bearingTo($destination);
        $expiresAt = Carbon::now()->addDays(rand(1, 8));

        $expiryMultiplier = match (true) {
            $expiresAt > Carbon::now()->addDays(5) && $expiresAt < Carbon::now()->addDays(7) => 1.2,
            $expiresAt > Carbon::now()->addDays(3) && $expiresAt < Carbon::now()->addDays(5) => 1.5,
            $expiresAt > Carbon::now()->addDays(1) && $expiresAt < Carbon::now()->addDays(3) => 1.8,
            $expiresAt < Carbon::now()->addHours(24) => 2.0,
            default => 1.0,
        };

        $value = $this->calcValue->execute($cargo->type, $cargo->qty, $distance) * $expiryMultiplier;

        return new ContractData(
            departure: $origin,
            destination: $destination,
            cargo: $cargo,
            contractType: ContractType::General,
            expiresAt: $expiresAt,
            value: $value,
            distance: $distance,
            heading: $heading,
        );
    }

    private function weightedPick(array $weights): mixed
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_key_first($weights);
        }
        $rand = random_int(1, $total);
        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $key;
            }
        }
        return array_key_last($weights);
    }
}
