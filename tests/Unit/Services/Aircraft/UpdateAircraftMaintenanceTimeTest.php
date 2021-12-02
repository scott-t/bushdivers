<?php

namespace Tests\Unit\Services\Aircraft;

use App\Models\Aircraft;
use App\Models\AircraftEngine;
use App\Models\Fleet;
use App\Services\Aircraft\UpdateAircraftMaintenanceTimes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAircraftMaintenanceTimeTest extends TestCase
{

    use RefreshDatabase;

    protected Model $aircraft;
    protected Model $aircraftEngine;
    protected Model $fleet;
    protected UpdateAircraftMaintenanceTimes $updateAircraftMaintenanceTimes;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->fleet = Fleet::factory()->create();
        $this->aircraft = Aircraft::factory()->create([
            'fleet_id' => $this->fleet->id
        ]);
        $this->aircraftEngine = AircraftEngine::factory()->create([
            'aircraft_id' => $this->aircraft->id
        ]);

        $this->updateAircraftMaintenanceTimes = $this->app->make(UpdateAircraftMaintenanceTimes::class);
    }

    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_tbo_time_is_updated()
    {
        $this->updateAircraftMaintenanceTimes->execute($this->aircraft->id, 32);
        $this->aircraftEngine->refresh();
        $this->assertEquals(32, $this->aircraftEngine->mins_since_tbo);
    }

    public function test_100hr_time_is_updated()
    {
        $this->updateAircraftMaintenanceTimes->execute($this->aircraft->id, 32);
        $this->aircraft->refresh();
        $this->assertEquals(32, $this->aircraft->mins_since_100hr);
    }
}
