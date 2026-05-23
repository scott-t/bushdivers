<?php

namespace Tests\Unit\Services\Contract;

use App\Models\Airport;
use App\Services\Contracts\Profiles\AirportContractProfile;
use App\Services\Contracts\Profiles\AirportProfileFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportProfileFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected AirportProfileFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->app->make(AirportProfileFactory::class);
    }

    /**
     * @dataProvider sizeProvider
     */
    public function test_each_size_returns_correct_profile(int $size, int $expectedMinJobs, int $expectedMaxJobs): void
    {
        $airport = Airport::factory()->create(['size' => $size, 'is_hub' => false]);
        $profile = $this->factory->fromAirport($airport);

        $this->assertInstanceOf(AirportContractProfile::class, $profile);
        $this->assertEquals($expectedMinJobs, $profile->minJobs);
        $this->assertEquals($expectedMaxJobs, $profile->maxJobs);
    }

    public static function sizeProvider(): array
    {
        return [
            'size 0' => [0, 2, 4],
            'size 1' => [1, 4, 8],
            'size 2' => [2, 6, 12],
            'size 3' => [3, 10, 20],
            'size 4' => [4, 15, 30],
            'size 5' => [5, 20, 40],
        ];
    }

    public function test_hub_overlay_increases_job_count(): void
    {
        $airport = Airport::factory()->create(['size' => 2, 'is_hub' => true]);
        $profile = $this->factory->fromAirport($airport);

        $this->assertEquals(30, $profile->minJobs);
        $this->assertEquals(60, $profile->maxJobs);
    }

    public function test_hub_overlay_rebalances_dest_size_bias(): void
    {
        $hubOverlayBias = config('contract_profiles.hub_overlay.dest_size_bias');
        $airport = Airport::factory()->create(['size' => 3, 'is_hub' => true]);
        $profile = $this->factory->fromAirport($airport);

        $this->assertEquals($hubOverlayBias, $profile->destSizeBias);
    }

    public function test_non_hub_does_not_use_overlay(): void
    {
        $airport = Airport::factory()->create(['size' => 3, 'is_hub' => false]);
        $profile = $this->factory->fromAirport($airport);

        // Size 3 base profile: min=10, max=20
        $this->assertEquals(10, $profile->minJobs);
        $this->assertEquals(20, $profile->maxJobs);
    }

    public function test_null_size_defaults_to_size_2_profile(): void
    {
        $airport = Airport::factory()->create(['size' => null, 'is_hub' => false]);
        $profile = $this->factory->fromAirport($airport);

        $this->assertEquals(6, $profile->minJobs);
        $this->assertEquals(12, $profile->maxJobs);
    }

    public function test_profile_has_range_bands(): void
    {
        $airport = Airport::factory()->create(['size' => 3, 'is_hub' => false]);
        $profile = $this->factory->fromAirport($airport);

        $this->assertNotEmpty($profile->rangeBands);
        foreach ($profile->rangeBands as $band) {
            $this->assertArrayHasKey('min', $band);
            $this->assertArrayHasKey('max', $band);
            $this->assertArrayHasKey('weight', $band);
        }

        $totalWeight = array_sum(array_column($profile->rangeBands, 'weight'));
        $this->assertEquals(100, $totalWeight, 'Range band weights must sum to 100');
    }

    public function test_profile_cargo_weights_sum_to_100(): void
    {
        foreach (range(0, 5) as $size) {
            $airport = Airport::factory()->create(['size' => $size, 'is_hub' => false]);
            $profile = $this->factory->fromAirport($airport);
            $total = array_sum($profile->cargoWeights);
            $this->assertEquals(100, $total, "Cargo weights for size {$size} must sum to 100");
        }
    }
}
