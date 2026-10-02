<?php

namespace Tests\Feature\Admin;

use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\SimType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncSimAirportsTest extends TestCase
{
    use RefreshDatabase;

    private const string HEADER = 'identifier,name,location,country,country_code,lat,lon,magnetic_variance,altitude,size,longest_runway_length,longest_runway_width,longest_runway_surface,has_avgas,has_jetfuel';

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function tempFile(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'airport-sync');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, array{0: float, 1: float}> $airports identifier => [lat, lon]
     */
    private function csv(array $airports): string
    {
        $lines = [self::HEADER];
        foreach ($airports as $identifier => [$lat, $lon]) {
            $lines[] = "{$identifier},{$identifier} Airport,Somewhere,Papua New Guinea,PG,{$lat},{$lon},0,100,3,2500,30,GRASS,true,false";
        }

        return $this->tempFile(implode("\n", $lines));
    }

    /**
     * @param list<string> $sims
     */
    private function airport(string $identifier, float $lat, float $lon, array $sims = ['fs20', 'fs24']): Airport
    {
        return Airport::factory()->create([
            'identifier' => $identifier,
            'lat' => $lat,
            'lon' => $lon,
            'sim_type' => $sims,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sync(string $file, array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('va:sync-sim-airports', array_merge([
            'file' => $file,
            '--sim' => 'fs24',
            '--report' => $this->tempFile(),
            '--force' => true,
        ], $options));
    }

    public function test_applies_swapped_and_chained_renames(): void
    {
        $a = $this->airport('AAAA', -6, 143);
        $b = $this->airport('BBBB', -7, 143);
        $c = $this->airport('CCCC', -8, 143);
        $d = $this->airport('DDDD', -9, 143);

        // A <-> B swap, C -> D -> EEEE chain
        $file = $this->csv([
            'BBBB' => [-6, 143],
            'AAAA' => [-7, 143],
            'DDDD' => [-8, 143],
            'EEEE' => [-9, 143],
        ]);

        $this->sync($file)->assertSuccessful();

        $this->assertEquals('BBBB', $a->fresh()->identifier);
        $this->assertEquals('AAAA', $b->fresh()->identifier);
        $this->assertEquals('DDDD', $c->fresh()->identifier);
        $this->assertEquals('EEEE', $d->fresh()->identifier);
        $this->assertEquals(4, Airport::count());
    }

    public function test_closes_tags_and_adds_airports(): void
    {
        $shared = $this->airport('AAAA', -6, 143);
        $fs24Only = $this->airport('BBBB', -7, 143, ['fs24']);
        $fs20Only = $this->airport('CCCC', -8, 143, ['fs20']);

        $file = $this->csv([
            'CCCC' => [-8, 143],
            'NEWW' => [-10, 143],
        ]);

        $this->sync($file)->assertSuccessful();

        $shared->refresh();
        $this->assertEquals('AAAA', $shared->identifier);
        $this->assertEquals([SimType::MSFS2020], $shared->sim_type->all());
        $this->assertFalse($shared->closed);

        $fs24Only->refresh();
        $this->assertEquals('[X]BBBB', $fs24Only->identifier);
        $this->assertTrue($fs24Only->closed);
        $this->assertEmpty($fs24Only->sim_type);

        $fs20Only->refresh();
        $this->assertEquals([SimType::MSFS2020, SimType::MSFS2024], $fs20Only->sim_type->all());

        $new = Airport::where('identifier', 'NEWW')->firstOrFail();
        $this->assertFalse($new->is_thirdparty);
        $this->assertFalse($new->closed);
        $this->assertEquals([SimType::MSFS2024], $new->sim_type->all());
        $this->assertEquals('G', $new->longest_runway_surface);
        $this->assertTrue($new->has_avgas);
    }

    public function test_promotes_third_party_airport(): void
    {
        $airport = Airport::factory()->create(['identifier' => 'TP01', 'lat' => -6, 'lon' => 143, 'is_thirdparty' => true]);

        $this->sync($this->csv(['AAAA' => [-6.005, 143]]))->assertSuccessful();

        $airport->refresh();
        $this->assertEquals('AAAA', $airport->identifier);
        $this->assertFalse($airport->is_thirdparty);
        $this->assertEquals([SimType::MSFS2024], $airport->sim_type->all());
    }

    public function test_renamed_airport_keeps_relationships(): void
    {
        $airport = $this->airport('AAAA', -6, 143);
        $aircraft = Aircraft::factory()->atAirport($airport)->create();

        $this->sync($this->csv(['BBBB' => [-6, 143]]))->assertSuccessful();

        $aircraft->refresh();
        $this->assertEquals($airport->id, $aircraft->current_airport_id);
        $this->assertEquals('BBBB', $aircraft->location->identifier);
    }

    public function test_dry_run_makes_no_changes_but_writes_report(): void
    {
        $airport = $this->airport('AAAA', -6, 143);
        $report = $this->tempFile();

        $this->sync($this->csv(['BBBB' => [-6, 143], 'NEWW' => [-10, 143]]), ['--dry-run' => true, '--report' => $report])
            ->assertSuccessful();

        $this->assertEquals('AAAA', $airport->fresh()->identifier);
        $this->assertEquals(1, Airport::count());

        $lines = file($report, FILE_IGNORE_NEW_LINES);
        $this->assertEquals('action,airport_id,old_identifier,new_identifier,name,distance_nm,note', $lines[0]);
        $this->assertCount(3, $lines);
    }

    public function test_asks_for_confirmation(): void
    {
        $airport = $this->airport('AAAA', -6, 143);

        $this->sync($this->csv(['BBBB' => [-6, 143]]), ['--force' => false])
            ->expectsConfirmation('Apply these changes?', 'no')
            ->assertSuccessful();

        $this->assertEquals('AAAA', $airport->fresh()->identifier);
    }

    public function test_invalid_csv_is_rejected(): void
    {
        $airport = $this->airport('AAAA', -6, 143);

        $file = $this->tempFile(self::HEADER . "\n"
            . "BBBB,B Airport,Somewhere,PNG,PG,-6,143,0,100,3,2500,30,GRASS,true,false\n"
            . "BBBB,Dupe Airport,Somewhere,PNG,PG,-7,143,0,100,3,2500,30,LAVA,true,false");

        $this->sync($file)->assertFailed();

        $this->assertEquals('AAAA', $airport->fresh()->identifier);
    }

    public function test_requires_valid_sim(): void
    {
        $this->sync($this->csv([]), ['--sim' => 'fs98'])->assertFailed();
    }
}
