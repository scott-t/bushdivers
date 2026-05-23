<?php

namespace Tests\Unit\Services\Contract;

use App\Models\Airport;
use App\Models\Contract;
use App\Models\Enums\CargoType;
use App\Services\Contracts\ContractGenerationOrchestrator;
use App\Services\Contracts\GenerationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractGenerationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected ContractGenerationOrchestrator $orchestrator;

    protected Airport $origin;
    protected Airport $nearby;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Supplies', 'min_cargo_split' => 100],
            ['type' => CargoType::Passenger->value, 'text' => 'Passengers', 'min_cargo_split' => 1],
        ]);

        // Two airports roughly 40 NM apart (within the short-range band)
        $this->origin = Airport::factory()->create([
            'identifier' => 'AYMR',
            'lat' => -6.36188,
            'lon' => 143.23070,
            'is_hub' => false,
            'size' => 2,
        ]);

        $this->nearby = Airport::factory()->create([
            'identifier' => 'AYFO',
            'lat' => -6.50917,
            'lon' => 143.07904,
            'is_hub' => false,
            'size' => 2,
        ]);

        $this->orchestrator = $this->app->make(ContractGenerationOrchestrator::class);
    }

    public function test_outbound_generates_contracts(): void
    {
        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);

        $count = Contract::where('dep_airport_id', $this->origin->id)->count();
        $this->assertGreaterThan(0, $count, 'Expected at least one outbound contract to be generated');
    }

    public function test_outbound_does_not_exceed_max_jobs(): void
    {
        $profile = config('contract_profiles.' . $this->origin->size);
        $maxJobs = $profile['max_jobs'];

        // Run twice to ensure it doesn't pile up
        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);
        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);

        $count = Contract::where('dep_airport_id', $this->origin->id)
            ->where('is_available', true)
            ->where('is_completed', false)
            ->count();

        $this->assertLessThanOrEqual($maxJobs, $count, "Should not exceed max_jobs ({$maxJobs})");
    }

    public function test_outbound_returns_early_when_already_at_max(): void
    {
        $profile = config('contract_profiles.' . $this->origin->size);
        $maxJobs = $profile['max_jobs'];

        // Pre-fill up to maxJobs
        for ($i = 0; $i < $maxJobs; $i++) {
            Contract::factory()->create([
                'dep_airport_id'     => $this->origin->id,
                'arr_airport_id'     => $this->nearby->id,
                'current_airport_id' => $this->origin->id,
                'is_available'       => true,
                'is_completed'       => false,
            ]);
        }

        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);

        $count = Contract::where('dep_airport_id', $this->origin->id)
            ->where('is_available', true)
            ->where('is_completed', false)
            ->count();

        $this->assertEquals($maxJobs, $count, 'Should not generate more contracts when already at max');
    }

    public function test_inbound_returns_early_when_above_threshold(): void
    {
        // size 2 threshold = 4; pre-fill 5 inbound contracts
        for ($i = 0; $i < 5; $i++) {
            Contract::factory()->create([
                'dep_airport_id'     => $this->nearby->id,
                'arr_airport_id'     => $this->origin->id,
                'current_airport_id' => $this->nearby->id,
                'is_available'       => true,
                'is_completed'       => false,
            ]);
        }

        $beforeCount = Contract::where('dep_airport_id', $this->nearby->id)->count();

        $this->orchestrator->execute($this->origin, GenerationMode::Inbound);

        $afterCount = Contract::where('dep_airport_id', $this->nearby->id)->count();
        $this->assertEquals($beforeCount, $afterCount, 'Should not generate when inbound count >= threshold');
    }

    public function test_inbound_triggers_source_generation_when_below_threshold(): void
    {
        // size-0 airport has threshold = 0, so inbound will always return early — use a hub
        $hubAirport = Airport::factory()->create([
            'identifier' => 'AYHB',
            'lat'        => -6.36188,
            'lon'        => 143.50,
            'is_hub'     => true,
            'hub_in_progress' => false,
            'size'       => 4,
        ]);

        // Hub threshold = 10; 0 inbound contracts currently
        // Nearby source (origin) is below its minJobs
        $this->orchestrator->execute($hubAirport, GenerationMode::Inbound);

        // The source airport (origin, ~40nm away) should have had contracts generated targeting hub
        // We just assert something was created; exact count depends on destinations available
        $total = Contract::count();
        $this->assertGreaterThan(0, $total);
    }
}
