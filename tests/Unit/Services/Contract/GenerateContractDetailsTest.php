<?php

namespace Tests\Unit\Services\Contract;

use App\Models\Airport;
use App\Models\Contract;
use App\Models\Enums\CargoType;
use App\Services\Contracts\ContractGenerationOrchestrator;
use App\Services\Contracts\GenerationMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateContractDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected ContractGenerationOrchestrator $orchestrator;
    protected Model $origin;
    protected Model $destination;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('cargo_types')->insert([
            ['type' => CargoType::Cargo->value, 'text' => 'Solar Panels', 'min_cargo_split' => 100],
            ['type' => CargoType::Cargo->value, 'text' => 'Building materials', 'min_cargo_split' => 200],
            ['type' => CargoType::Passenger->value, 'text' => 'Medics', 'min_cargo_split' => 1],
            ['type' => CargoType::Passenger->value, 'text' => 'Locals', 'min_cargo_split' => 1],
        ]);

        $this->origin = Airport::factory()->create([
            'identifier' => 'AYMR',
            'name' => 'Moro',
            'country' => 'PG',
            'is_hub' => false,
            'lat' => -6.36188,
            'lon' => 143.23070,
            'altitude' => 100,
            'size' => 2,
        ]);

        $this->destination = Airport::factory()->create([
            'identifier' => 'AYFO',
            'name' => 'Fogomaiu Airstrip',
            'country' => 'PG',
            'is_hub' => false,
            'lat' => -6.50917,
            'lon' => 143.07904,
            'altitude' => 100,
            'size' => 2,
        ]);

        $this->orchestrator = $this->app->make(ContractGenerationOrchestrator::class);
    }

    public function test_outbound_contracts_are_stored(): void
    {
        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);

        // At least one contract should have been stored from the origin
        $this->assertDatabaseHas('contracts', ['dep_airport_id' => $this->origin->id]);
    }

    public function test_generated_contract_has_valid_heading(): void
    {
        $this->orchestrator->execute($this->origin, GenerationMode::Outbound);

        $contract = Contract::where('dep_airport_id', $this->origin->id)->first();

        if ($contract) {
            $this->assertGreaterThanOrEqual(0, $contract->heading);
            $this->assertLessThan(360, $contract->heading);
        } else {
            $this->markTestSkipped('No destinations in range — test data too sparse');
        }
    }
}
