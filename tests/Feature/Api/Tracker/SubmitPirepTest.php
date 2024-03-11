<?php

namespace Tests\Feature\Api\Tracker;

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

class SubmitPirepTest extends TestCase
{
    use RefreshDatabase;

    protected Model $user;
    protected Model $pirep;
    protected Model $pirepCargo;
    protected Model $contract;
    protected Model $fleet;
    protected Model $aircraft;
    protected Model $booking;
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
            'flight_time_mins' => 0
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
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN',
            'current_airport_id' => 'AYMR',
        ]);

        $this->pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => $this->contract->arr_airport_id,
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
            'identifier' => 'AYMN'
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
        $response->assertStatus(200);
    }

    public function test_deadhead_pirep_submitted_successfully()
    {
        Artisan::call('db:seed --class=RankSeeder');
        Sanctum::actingAs(
            $this->user,
            ['*']
        );

        $pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => $this->contract->arr_airport_id,
            'departure_airport_id' => $this->contract->dep_airport_id,
            'aircraft_id' => $this->aircraft,
            'current_lat' => -6.14617,
            'current_lon' => 143.65733,
            'is_empty' => 1
        ]);

        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('user_accounts', [
            'flight_id' => $this->pirep->id
        ]);
    }

    public function test_pirep_submitted_with_flight_time()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('pireps', [
            'id' => $this->pirep->id,
            'flight_time' => 45
        ]);
    }

    public function test_pirep_submitted_with_landing_data()
    {
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
            'landing_rate' => 22.12,
            'landing_bank' => 2.12,
            'landing_pitch' => 5.12,
            'landing_lat' => -6.50818,
            'landing_lon' => 143.07856,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('pireps', [
            'id' => $this->pirep->id,
            'landing_lat' => -6.50818
        ]);
    }

    public function test_pirep_submitted_with_distance()
    {
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
            'distance' => 12,
            'landing_rate' => 22.12,
            'landing_bank' => 2.12,
            'landing_pitch' => 5.12,
            'landing_lat' => -6.50818,
            'landing_lon' => 143.07856,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('pireps', [
            'id' => $this->pirep->id,
            'distance' => 12
        ]);
    }

    public function test_pilot_calcs_peformed_when_pirep_submitted()
    {
        Event::fake();

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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        Event::assertDispatched(PirepFiled::class);
    }

    public function test_pilot_pay_calc_when_pirep_submitted()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $pp = (FinancialConsts::PilotPay / 100) * $this->contract->contract_value;

        $this->assertDatabaseHas('user_accounts', [
            'flight_id' => $this->pirep->id,
            'total' => $pp
        ]);
    }

    public function test_pilot_points_when_pirep_submitted()
    {
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
            'distance' => 100,
            'landing_rate' => 30.25,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('users', [
            'id' => $this->pirep->user_id,
            'points' => 59
        ]);
        $this->assertDatabaseHas('pireps', [
            'score' => 10
        ]);
        $this->assertDatabaseHas('points', [
            'pirep_id' => $this->pirep->id,
            'points' => PointsType::LANDING_RATE_3_39,
            'type_name' => PointsType::LANDING_RATE_3_39_LABEL
        ]);
    }

    public function test_pilot_location_and_flights_updated()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];


        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'current_airport_id' => $this->contract->arr_airport_id,
            'flights_time' => $this->user->flights_time + 45,
            'flights' => $this->user->flights + 1,
        ]);
    }

    public function test_aircraft_location_and_state_updated()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $location = $this->contract->arr_airport_id;
        $hours = $this->aircraft->flight_time_mins += 45;
        $fuel = $this->aircraft->fuel_onboard -= 25;

        $this->postJson('/api/pirep/submit', $data);

        $pirep = Pirep::where('aircraft_id', $this->aircraft->id)->first();

        $this->assertDatabaseHas('aircraft', [
            'id' => $this->aircraft->id,
            'flight_time_mins' => $hours,
            'fuel_onboard' => $fuel,
            'state' => AircraftState::AVAILABLE,
            'current_airport_id' => $location,
            'last_flight' => $pirep->submitted_at,
            'user_id' => null,
            'last_lat' => $this->pirep->current_lat,
            'last_lon' => $this->pirep->current_lon
        ]);
    }

    public function test_pilot_gets_rank_upgraded()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'rank_id' => 2
        ]);
    }

    public function test_pilot_gets_award_added()
    {
        Artisan::call('db:seed --class=RankSeeder');
        Artisan::call('db:seed --class=AwardSeeder');
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('award_user', [
            'user_id' => $this->user->id,
            'award_id' => 1
        ]);
    }

    public function test_contract_cargo_completed()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $this->contract->id,
            'is_completed' => true,
            'current_airport_id' => $this->pirep->destination_airport_id
        ]);
    }

    public function test_contract_cargo_not_completed()
    {
        $pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => 'AYTE',
            'departure_airport_id' => $this->contract->dep_airport_id,
            'aircraft_id' => $this->aircraft
        ]);

        $pirepCargo = PirepCargo::factory()->create([
            'pirep_id' => $pirep->id,
            'contract_cargo_id' => $this->contract->id
        ]);


        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $this->contract->id,
            'is_completed' => false,
            'current_airport_id' => 'AYTE',
            'user_id' => null
        ]);
    }

    public function test_contract_completed_and_paid()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $this->contract->id,
            'is_completed' => true,
            'is_paid' => true
        ]);
    }

    public function test_multiple_items_for_different_contract_completed()
    {
        $contract1 = Contract::factory()->create([
            'contract_value' => 250.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN',
            'user_id' => $this->user->id
        ]);

        $contract2 = Contract::factory()->create([
            'contract_value' => 400.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN',
            'user_id' => $this->user->id
        ]);

        $p = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => 'AYMN',
            'departure_airport_id' => 'AYMR',
            'aircraft_id' => $this->aircraft
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $p->id,
            'contract_cargo_id' => $contract1->id
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $p->id,
            'contract_cargo_id' => $contract2->id
        ]);

        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $p->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract1->id,
            'is_completed' => true
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract2->id,
            'is_completed' => true
        ]);
    }

    public function test_multiple_items_for_different_contract_not_completed()
    {
        $contract1 = Contract::factory()->create([
            'contract_value' => 250.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN'
        ]);

        $contract2 = Contract::factory()->create([
            'contract_value' => 400.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN'
        ]);

        $pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => 'WAVG',
            'departure_airport_id' => $contract1->dep_airport_id,
            'aircraft_id' => $this->aircraft
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $pirep->id,
            'contract_cargo_id' => $contract1->id
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $pirep->id,
            'contract_cargo_id' => $contract2->id
        ]);

        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract1->id,
            'is_completed' => false
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract2->id,
            'is_completed' => false
        ]);
    }

    public function test_multiple_items_for_different_contract_one_completed_one_not_completed()
    {
        $contract1 = Contract::factory()->create([
            'contract_value' => 250.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'AYMN'
        ]);

        $contract2 = Contract::factory()->create([
            'contract_value' => 400.00,
            'dep_airport_id' => 'AYMR',
            'arr_airport_id' => 'WAVG'
        ]);

        $pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => $contract1->arr_airport_id,
            'departure_airport_id' => $contract1->dep_airport_id,
            'aircraft_id' => $this->aircraft
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $pirep->id,
            'contract_cargo_id' => $contract1->id
        ]);

        PirepCargo::factory()->create([
            'pirep_id' => $pirep->id,
            'contract_cargo_id' => $contract2->id
        ]);

        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "05/10/2021 01:45:00";

        $data = [
            'pirep_id' => $pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $this->postJson('/api/pirep/submit', $data);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract1->id,
            'is_completed' => true
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract2->id,
            'is_completed' => false
        ]);
    }

    public function test_pirep_fails_gracefully_with_invalid_date()
    {
        Artisan::call('db:seed --class=RankSeeder');
        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "test";

        $data = [
            'pirep_id' => $this->pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $response->assertStatus(400);
    }

    public function test_pirep_fails_and_rolls_back()
    {
        Artisan::call('db:seed --class=RankSeeder');
        Sanctum::actingAs(
            $this->user,
            ['*']
        );
        $startTime = "05/10/2021 01:00:00";
        $endTime = "test";

        $data = [
            'pirep_id' => $this->pirep->id,
            'fuel_used' => 25,
            'distance' => 76,
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $response = $this->postJson('/api/pirep/submit', $data);

        $response->assertStatus(400);
    }

    public function test_aircraft_maintenance_times_are_updated()
    {
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
            'landing_rate' => -149.12,
            'block_off_time'=> $startTime,
            'block_on_time' => $endTime
        ];

        $previous = $this->aircraft->flight_time_mins;
        $this->postJson('/api/pirep/submit', $data);

        $this->aircraftEngine->refresh();
        $this->aircraft->refresh();

        $this->assertEquals($previous+45, $this->aircraftEngine->mins_since_100hr);
        $this->assertEquals($previous+45, $this->aircraftEngine->mins_since_tbo);
    }
}
