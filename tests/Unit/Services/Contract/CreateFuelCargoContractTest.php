<?php

namespace Services\Contract;

use App\Models\Airport;
use App\Services\Airports\CalcDistanceBetweenPoints;
use App\Services\Contracts\CalcContractValue;
use App\Services\Contracts\CreateFuelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Services\Contract\CalculateContractValueTest;

class CreateFuelCargoContractTest extends TestCase
{
    use RefreshDatabase;
    protected Model $airport1;
    protected Model $airport2;
    protected CreateFuelContract $createFuelContract;
    protected CalcContractValue $calcContractValue;

    protected CalcDistanceBetweenPoints $calcDistanceBetweenPoints;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->createFuelContract = $this->app->make(CreateFuelContract::class);
        $this->calcContractValue = $this->app->make(CalcContractValue::class);
        $this->calcDistanceBetweenPoints = $this->app->make(CalcDistanceBetweenPoints::class);
        $this->airport1 = Airport::factory()->create([
            'identifier' => 'AYMN',
            'name' => 'Mendi',
            'country' => 'PG',
            'is_hub' => false,
            'lat' => -6.14617,
            'lon' => 143.65733,
            'altitude' => 100,
            'avgas_qty' => 100
        ]);
        $this->airport2 = Airport::factory()->create([
            'identifier' => 'AYFO',
            'name' => 'Fogomaiu Airstrip',
            'country' => 'PG',
            'is_hub' => false,
            'lat' => -6.50917,
            'lon' => 143.07904,
            'altitude' => 100
        ]);
    }

    /**
     * A basic unit test example.
     */
    public function test_contract_created(): void
    {
        $this->createFuelContract->execute($this->airport1->identifier, $this->airport2->identifier, 10, 1, 50, 1);
        $this->assertDatabaseHas('contracts', [
            'dep_airport_id' => $this->airport1->identifier,
            'arr_airport_id' => $this->airport2->identifier,
            'is_available' => 0,
            'is_fuel' => true,
            'user_id' => 1
        ]);
    }

    public function test_contract_value_is_halved_when_qty_high(): void
    {
        $distance = $this->calcDistanceBetweenPoints->execute($this->airport1->lat, $this->airport1->lon, $this->airport2->lat, $this->airport2->lon);
        $normalValue = $this->calcContractValue->execute(1, 3500, $distance);
        $fuelValue = round(($normalValue / 2) + 2000);
        $this->createFuelContract->execute($this->airport1->identifier, $this->airport2->identifier, 10, 1, 3500, 1);
        $this->assertDatabaseHas('contracts', [
            'dep_airport_id' => $this->airport1->identifier,
            'arr_airport_id' => $this->airport2->identifier,
            'is_available' => 0,
            'is_fuel' => true,
            'user_id' => 1,
            'contract_value' => $fuelValue
        ]);
    }

    public function test_fuel_decremented_from_airport(): void
    {
        $this->createFuelContract->execute($this->airport1->identifier, $this->airport2->identifier, 10, 1, 50, 1);
        $this->assertDatabaseHas('airports', [
            'identifier' => $this->airport1->identifier,
            'avgas_qty' => 90
        ]);
    }
}