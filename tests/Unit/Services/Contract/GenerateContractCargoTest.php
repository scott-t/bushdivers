<?php

namespace Tests\Unit\Services\Contract;

use App\Models\Enums\CargoType;
use App\Services\Contracts\GenerateContractCargo;
use App\Services\Contracts\Profiles\AirportContractProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateContractCargoTest extends TestCase
{
    use RefreshDatabase;

    protected GenerateContractCargo $generateContractCargo;
    protected AirportContractProfile $defaultProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generateContractCargo = $this->app->make(GenerateContractCargo::class);

        // A generic profile used by most tests
        $this->defaultProfile = AirportContractProfile::fromArray(config('contract_profiles.2'));
    }

    public function test_cargo_qty_is_multiple_of_min_cargo_split(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Timber', 'min_cargo_split' => 500],
        ]);

        // Use a profile with a high max so the cargo type survives the filter
        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.2'),
            ['cargo_weights' => ['cargo' => 100, 'pax' => 0], 'max_cargo_lbs' => 20000]
        ));

        for ($i = 0; $i < 20; $i++) {
            $result = $this->generateContractCargo->execute($profile);
            $this->assertEquals(0, $result->qty % 500, "qty {$result->qty} is not a multiple of 500");
        }
    }

    public function test_passenger_qty_is_multiple_of_min_cargo_split(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Passenger->value, 'text' => 'Workers', 'min_cargo_split' => 2],
        ]);

        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.2'),
            ['cargo_weights' => ['cargo' => 0, 'pax' => 100], 'max_pax' => 20]
        ));

        for ($i = 0; $i < 20; $i++) {
            $result = $this->generateContractCargo->execute($profile);
            $this->assertEquals(0, $result->qty % 2, "qty {$result->qty} is not a multiple of 2");
            $this->assertGreaterThanOrEqual(2, $result->qty);
        }
    }

    public function test_cargo_qty_is_not_rounded_when_min_split_is_one(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Miscellaneous', 'min_cargo_split' => 1],
        ]);

        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.2'),
            ['cargo_weights' => ['cargo' => 100, 'pax' => 0], 'max_cargo_lbs' => 20000]
        ));

        $result = $this->generateContractCargo->execute($profile);
        $this->assertGreaterThan(0, $result->qty);
    }

    public function test_cargo_filtered_by_max_cargo_lbs(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Heavy Equipment', 'min_cargo_split' => 5000],
            ['type' => CargoType::Cargo->value, 'text' => 'Small Parcel', 'min_cargo_split' => 100],
        ]);

        // Profile with max_cargo_lbs = 800 — only 'Small Parcel' (split 100) should be picked, not 'Heavy Equipment' (split 5000)
        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.0'),
            ['cargo_weights' => ['cargo' => 100, 'pax' => 0], 'max_cargo_lbs' => 800]
        ));

        for ($i = 0; $i < 10; $i++) {
            $result = $this->generateContractCargo->execute($profile);
            $this->assertNotEquals('Heavy Equipment', $result->name, 'Heavy Equipment should be excluded by max_cargo_lbs');
        }
    }

    public function test_falls_back_to_pax_when_no_cargo_type_fits(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Giant Machinery', 'min_cargo_split' => 50000],
            ['type' => CargoType::Passenger->value, 'text' => 'Locals', 'min_cargo_split' => 1],
        ]);

        // max_cargo_lbs = 100 — no cargo type has min_cargo_split <= 100, so must fall back to pax
        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.0'),
            ['cargo_weights' => ['cargo' => 100, 'pax' => 0], 'max_cargo_lbs' => 100, 'max_pax' => 4]
        ));

        $result = $this->generateContractCargo->execute($profile);
        $this->assertEquals(CargoType::Passenger, $result->type);
    }

    public function test_pax_bounded_by_max_pax(): void
    {
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Passenger->value, 'text' => 'Passengers', 'min_cargo_split' => 1],
        ]);

        $profile = AirportContractProfile::fromArray(array_merge(
            config('contract_profiles.0'),
            ['cargo_weights' => ['cargo' => 0, 'pax' => 100], 'max_pax' => 4]
        ));

        for ($i = 0; $i < 20; $i++) {
            $result = $this->generateContractCargo->execute($profile);
            $this->assertLessThanOrEqual(4, $result->qty, "pax qty {$result->qty} exceeds max_pax of 4");
            $this->assertGreaterThanOrEqual(1, $result->qty);
        }
    }
}
