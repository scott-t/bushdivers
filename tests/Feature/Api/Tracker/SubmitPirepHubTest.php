<?php

namespace Api\Tracker;

use App\Events\PirepFiled;
use App\Models\Aircraft;
use App\Models\AircraftEngine;
use App\Models\AirlineFees;
use App\Models\Airport;
use App\Models\Contract;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AirlineTransactionTypes;
use App\Models\Enums\FinancialConsts;
use App\Models\Enums\PointsType;
use App\Models\Enums\TransactionTypes;
use App\Models\Fleet;
use App\Models\FlightLog;
use App\Models\Pirep;
use App\Models\PirepCargo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmitPirepHubTest extends TestCase
{
    use RefreshDatabase;

    protected Model $user;
    protected Model $pirep;
    protected Model $pirepCargo;
    protected Model $contract;
    protected Model $fleet;
    protected Model $aircraft;
    protected Model $aircraftEngine;

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->user = User::factory()->create([
            'rank_id' => 1,
            'flights_time' => 299,
            'points' => 49,
            'created_at' => Carbon::now()->addYears(-2)
        ]);
        $this->fleet = Fleet::factory()->create([
            'fuel_type' => 1,
            'size' => 'S'
        ]);
        $this->aircraft = Aircraft::factory()->create([
            'fleet_id' => $this->fleet->id,
            'fuel_onboard' => 50,
            'current_airport_id' => 'AYMR',
            'user_id' => $this->user->id,
            'flight_time_mins' => 0,
            'is_ferry' => true,
            'ferry_user_id' => $this->user->id,
            'hub_id' => 'AYMN'
        ]);

        $this->aircraftEngine = AircraftEngine::factory()->create([
            'aircraft_id' => $this->aircraft->id
        ]);

        DB::table('cargo_types')->insert([
            ['type' => 1, 'text' => 'Solar Panels'],
            ['type' => 1, 'text' => 'Building materials'],
            ['type' => 2, 'text' => 'Medics'],
            ['type' => 2, 'text' => 'Locals'],
        ]);

        $this->contract = Contract::factory()->create([
            'contract_value' => 250.00,
            'contract_type_id' => 5,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN',
            'current_airport_id' => 'AYMR',
            'airport' => 'AYMN'
        ]);

        $this->pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => 'AYMN',
            'departure_airport_id' => $this->contract->dep_airport_id,
            'aircraft_id' => $this->aircraft->id,
            'current_lat' => -6.14617,
            'current_lon' => 143.65733
        ]);

        $this->pirepCargo = PirepCargo::factory()->create([
            'pirep_id' => $this->pirep->id,
            'contract_cargo_id' => $this->contract->id
        ]);

        Airport::factory()->create([
            'identifier' => 'AYMR'
        ]);
        Airport::factory()->create([
            'identifier' => 'AYMN',
            'is_hub' => true,
            'hub_in_progress' => true,
        ]);

        FlightLog::factory()->create([
            'pirep_id' => $this->pirep->id,
            'lat' => -6.36263,
            'lon' => 143.23056
        ]);

        FlightLog::factory()->create([
            'pirep_id' => $this->pirep->id,
            'lat' => -6.14477,
            'lon' => 143.65752
        ]);

        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::FuelFees,
            'fee_name' => 'Avgas',
            'fee_amount' => 2.15
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::GroundHandlingFees,
            'fee_name' => 'Cargo Handling',
            'fee_weight' => 1,
            'fee_amount' => 0.15
        ]);
        AirlineFees::factory()->create([
            'fee_type' => AirlineTransactionTypes::LandingFees,
            'fee_name' => 'Landing Fees - Small',
            'fee_amount' => 35.00
        ]);

    }
    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_pirep_submitted_successfully()
    {
        $this->withExceptionHandling();
        Artisan::call('db:seed --class=RankSeeder');
        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $this->pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => 149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);
        $this->assertDatabaseHas('aircraft', [
            'is_ferry' => false,
            'ferry_user_id' => null
        ]);
        $this->assertDatabaseHas('airports', [
            'identifier' => 'AYMN',
            'hub_in_progress' => false
        ]);
        $response->assertStatus(200);
    }
}
