<?php

namespace Tests\Unit\Services\Financial;

use App\Models\Aircraft;
use App\Models\AirlineFees;
use App\Models\Airport;
use App\Models\Enums\AirlineTransactionTypes;
use App\Models\Fleet;
use App\Services\FinancialsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AircraftFeeTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialsService $financialsService;
    protected Model $fleetSmall;
    protected Model $fleetMedium;
    protected Model $fleetLarge;
    protected Model $aircraftSmall;
    protected Model $aircraftMedium;
    protected Model $aircraftLarge;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->financialsService = new FinancialsService();
        $this->fleetSmall = Fleet::factory()->create(['size' => 'S', 'type' => 'C172']);
        $this->fleetMedium = Fleet::factory()->create(['size' => 'M', 'type' => 'C208']);
        $this->fleetLarge = Fleet::factory()->create(['size' => 'L', 'type' => 'TBM9']);
        $this->aircraftSmall = Aircraft::factory()->create([
            'registration' => 'P2-SM',
            'fleet_id' => $this->fleetSmall->id
        ]);
        $this->aircraftMedium = Aircraft::factory()->create([
            'registration' => 'P2-MD',
            'fleet_id' => $this->fleetMedium->id
        ]);
        $this->aircraftLarge = Aircraft::factory()->create([
            'registration' => 'P2-LG',
            'fleet_id' => $this->fleetLarge->id
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftStorageFees,
            'fee_name' => 'Aircraft Parking - Small',
            'fee_amount' => 250
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftStorageFees,
            'fee_name' => 'Aircraft Parking - Medium',
            'fee_amount' => 1350
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftStorageFees,
            'fee_name' => 'Aircraft Parking - Large',
            'fee_amount' => 2500
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftRentalFee,
            'fee_name' => 'Aircraft Ownership - Small',
            'fee_amount' => 3000
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftRentalFee,
            'fee_name' => 'Aircraft Ownership - Medium',
            'fee_amount' => 7500
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::AircraftRentalFee,
            'fee_name' => 'Aircraft Ownership - Large',
            'fee_amount' => 15000
        ]);
    }

    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_small_transaction_calculated()
    {
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftStorageFees,
            'total' => -250,
            'memo' => 'Parking: '. $this->aircraftSmall->registration
        ]);
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftRentalFee,
            'total' => -3000,
            'memo' => 'Rental: '. $this->aircraftSmall->registration
        ]);
    }

    public function test_medium_transaction_calculated()
    {
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftStorageFees,
            'total' => -1350,
            'memo' => 'Parking: '. $this->aircraftMedium->registration
        ]);
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftRentalFee,
            'total' => -7500,
            'memo' => 'Rental: '. $this->aircraftMedium->registration
        ]);
    }

    public function test_large_transaction_calculated()
    {
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftStorageFees,
            'total' => -2500,
            'memo' => 'Parking: '. $this->aircraftLarge->registration
        ]);
        $this->financialsService->calcAircraftFees();
        $this->assertDatabaseHas('account_ledgers', [
            'transaction_type' => AirlineTransactionTypes::AircraftRentalFee,
            'total' => -15000,
            'memo' => 'Rental: '. $this->aircraftLarge->registration
        ]);
    }
}
