<?php

namespace Tests\Unit\Services\AirportSync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AirportSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyse_classifies_matches_and_deactivations(): void
    {
        $existing = Airport::factory()->create([
            'identifier' => 'AYMR',
            'lat' => -6.36188,
            'lon' => 143.23070,
            'sim_type' => ['fs20'],
            'is_thirdparty' => false,
        ]);

        $thirdParty = Airport::factory()->create([
            'identifier' => 'BDV0001',
            'lat' => -6.50000,
            'lon' => 143.10000,
            'sim_type' => ['fs20'],
            'is_thirdparty' => true,
            'user_id' => null,
        ]);

        $toDeactivate = Airport::factory()->create([
            'identifier' => 'DEACT',
            'lat' => -6.10000,
            'lon' => 143.00000,
            'sim_type' => ['fs20'],
            'is_thirdparty' => false,
        ]);

        $incoming = new Collection([
            [
                'identifier' => 'AYMR',
                'name' => 'Moro',
                'location' => 'Moro',
                'country' => 'PG',
                'country_code' => 'PG',
                'lat' => -6.36300,
                'lon' => 143.23100,
                'altitude' => 10,
                'magnetic_variance' => 1,
                'longest_runway_length' => 1000,
                'longest_runway_surface' => 'A',
                'has_avgas' => true,
                'has_jetfuel' => true,
                'size' => 3,
            ],
            [
                'identifier' => 'AYTP',
                'name' => 'Third Party Promote',
                'location' => 'Somewhere',
                'country' => 'PG',
                'country_code' => 'PG',
                'lat' => -6.50010,
                'lon' => 143.10000,
                'altitude' => 10,
                'magnetic_variance' => 1,
                'longest_runway_length' => 1000,
                'longest_runway_surface' => 'A',
                'has_avgas' => true,
                'has_jetfuel' => true,
                'size' => 3,
            ],
            [
                'identifier' => 'NEW1',
                'name' => 'Brand New',
                'location' => 'Nowhere',
                'country' => 'PG',
                'country_code' => 'PG',
                'lat' => -9.00000,
                'lon' => 150.00000,
                'altitude' => 10,
                'magnetic_variance' => 1,
                'longest_runway_length' => 1000,
                'longest_runway_surface' => 'A',
                'has_avgas' => true,
                'has_jetfuel' => true,
                'size' => 3,
            ],
        ]);

        $results = (new AirportSyncService())->analyse($incoming, SimType::MSFS2020);

        $this->assertCount(1, $results['auto_updates']);
        $this->assertSame($existing->id, $results['auto_updates'][0]['matched_id']);

        $this->assertCount(1, $results['review_items']);
        $this->assertSame('promote_thirdparty', $results['review_items'][0]['type']);
        $this->assertSame($thirdParty->id, $results['review_items'][0]['candidate']['id']);

        $this->assertCount(1, $results['new_airports']);
        $this->assertSame('NEW1', $results['new_airports'][0]['incoming']['identifier']);

        $this->assertCount(1, $results['deactivations']);
        $this->assertSame($toDeactivate->id, $results['deactivations'][0]['id']);
    }
}
