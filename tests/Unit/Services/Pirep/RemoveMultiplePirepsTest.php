<?php

namespace Tests\Unit\Services\Pirep;

use App\Models\Aircraft;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractCargo;
use App\Models\Enums\AircraftState;
use App\Models\Fleet;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\PirepCargo;
use App\Models\User;
use App\Services\PirepService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoveMultiplePirepsTest extends TestCase
{
    use RefreshDatabase;

    protected Model $user;
    protected Model $pirep;
    protected Model $pirepCargo;
    protected Model $contract;
    protected Model $contractCargo;
    protected Model $fleet;
    protected Model $aircraft;
    protected Model $booking;
    protected PirepService $pirepService;

    public function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->pirepService = new PirepService();

        $this->user = User::factory()->create([
            'rank_id' => 1,
            'flights_time' => 299,
            'points' => 49,
            'created_at' => Carbon::now()->addYears(-2)
        ]);
        $this->fleet = Fleet::factory()->create();
        $this->aircraft = Aircraft::factory()->create([
            'fleet_id' => $this->fleet->id,
            'fuel_onboard' => 50,
            'current_airport_id' => 'AYMR',
            'user_id' => $this->user->id
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
            'arr_airport_id' => 'AYMN'
        ]);
        $this->contractCargo = ContractCargo::factory()->create([
            'contract_id' => $this->contract->id,
            'current_airport_id' => $this->contract->dep_airport_id
        ]);
        $this->pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'destination_airport_id' => $this->contract->arr_airport_id,
            'departure_airport_id' => $this->contract->dep_airport_id,
            'aircraft_id' => $this->aircraft
        ]);

        $this->pirepCargo = PirepCargo::factory()->create([
            'pirep_id' => $this->pirep->id,
            'contract_cargo_id' => $this->contractCargo->id
        ]);
    }
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_one_pirep_removed()
    {
        $this->pirepService->removeMultiplePireps($this->pirep);
        $this->assertDatabaseMissing('pireps', ['id' => $this->pirep->id]);
    }

    public function test_multiple_pireps_removed()
    {
        $aircraft = Aircraft::factory()->create([
            'fleet_id' => $this->fleet->id
        ]);

        $pirep = Pirep::factory()->create([
            'user_id' => $this->user->id,
            'aircraft_id' => $aircraft->id,
            'landing_rate' => 150,
            'distance' => 50,
            'flight_time' => 60
        ]);
        $pireps = collect([$pirep, $this->pirep]);
        $this->pirepService->removeMultiplePireps($pireps);
        $this->assertDatabaseMissing('pireps', ['id' => $this->pirep->id]);
        $this->assertDatabaseMissing('pireps', ['id' => $pirep->id]);
    }

    public function test_aircraft_updated()
    {
        $this->pirepService->removeMultiplePireps($this->pirep);
        $this->assertDatabaseHas('aircraft', ['id' => $this->pirep->aircraft_id, 'state' => AircraftState::AVAILABLE]);
    }
}
