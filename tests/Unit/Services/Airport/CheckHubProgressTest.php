<?php

namespace Tests\Unit\Services\Airport;

use App\Models\Airport;
use App\Models\Contract;
use App\Services\Airports\CheckHubProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckHubProgressTest extends TestCase
{
    use RefreshDatabase;
    protected Model $airport;
    protected CheckHubProgress $checkHubProgress;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->airport = Airport::factory()->create([
            'identifier' => 'EGCC',
            'is_hub' => true,
            'hub_in_progress' => true
        ]);
        $this->checkHubProgress = $this->app->make(CheckHubProgress::class);
    }

    /**
     * A basic unit test example.
     */
    public function test_hub_opened(): void
    {
        $contract = Contract::factory()->create([
            'arr_airport_id' => 'EGCC',
            'is_completed' => true,
            'airport' => 'EGCC'
        ]);
        $this->checkHubProgress->execute('EGCC');
        $this->airport->refresh();
        $this->assertEquals(0,$this->airport->hub_in_progress);
    }

    public function test_hub_not_opened(): void
    {
        $contract = Contract::factory()->create([
            'arr_airport_id' => 'EGCC',
            'is_completed' => false,
            'airport' => 'EGCC'
        ]);
        $this->checkHubProgress->execute('EGCC');
        $this->airport->refresh();
        $this->assertEquals(1,$this->airport->hub_in_progress);
    }
}
