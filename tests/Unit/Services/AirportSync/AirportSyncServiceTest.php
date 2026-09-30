<?php

namespace Tests\Unit\Services\AirportSync;

use App\Models\Airport;
use App\Models\Enums\SimType;
use App\Models\Pirep;
use App\Services\AirportSync\AirportSyncConflictException;
use App\Services\AirportSync\AirportSyncExecutor;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\SyncOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AirportSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    // Two airfields ~84nm apart
    private const X = [-6.0, 145.0];
    private const Y = [-7.0, 146.0];

    private AirportSyncService $service;

    private AirportSyncExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AirportSyncService::class);
        $this->executor = app(AirportSyncExecutor::class);
    }

    public function test_rename_into_identifier_held_by_another_airport_is_blocking_until_recoded(): void
    {
        $a = $this->airport('AAAA', self::X);
        $b = $this->airport('BBBB', self::Y);

        // The sim now calls the airfield at X "BBBB", and has nothing at Y
        $changeset = $this->analyse([$this->row('BBBB', self::X)]);

        $rename = $this->opOfType($changeset, SyncOp::RENAME);
        $this->assertSame($a->id, $rename['airport']['id']);
        $this->assertSame(SyncOp::APPLY, $rename['decision']);
        $this->assertSame($b->id, $this->opOfType($changeset, SyncOp::UNTAG)['airport']['id']);
        $this->assertEmpty(collect($changeset['operations'])->where('type', SyncOp::RELOCATE));

        $this->assertCount(1, $changeset['conflicts']);
        $this->assertSame('identifier_conflict', $changeset['conflicts'][0]['type']);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            collect($changeset['conflicts'][0]['parties'])->pluck('airport_id')->all()
        );

        $this->expectConflictOnExecute($changeset);

        $changeset['identifier_overrides'][$b->id] = 'BBBB-OLD';
        $changeset = $this->service->refresh($changeset);
        $this->assertEmpty($changeset['conflicts']);

        $this->executor->execute($changeset, true);

        $a->refresh();
        $b->refresh();
        $this->assertSame('BBBB', $a->identifier);
        $this->assertEquals(self::X[0], (float) $a->lat);
        $this->assertSame('BBBB-OLD', $b->identifier);
        $this->assertEquals(self::Y[0], (float) $b->lat);
        $this->assertFalse($b->sim_type->contains(SimType::MSFS2020));
    }

    public function test_identifier_swap_keeps_airports_in_place(): void
    {
        $a = $this->airport('AAAA', self::X);
        $b = $this->airport('BBBB', self::Y);

        $changeset = $this->analyse([
            $this->row('BBBB', self::X),
            $this->row('AAAA', self::Y),
        ]);

        // Both identifiers still exist in the import, so a swap is always reviewed
        $this->assertCount(2, collect($changeset['operations'])->where('type', SyncOp::RENAME));
        $this->assertSame(2, $changeset['summary']['unresolved']);
        $this->assertEmpty($changeset['conflicts']);

        $summary = $this->executor->execute($this->decideAll($changeset, SyncOp::RENAME, SyncOp::APPLY));

        $this->assertSame('BBBB', $a->refresh()->identifier);
        $this->assertSame('AAAA', $b->refresh()->identifier);
        $this->assertEquals(self::X[0], (float) $a->lat);
        $this->assertEquals(self::Y[0], (float) $b->lat);
        $this->assertSame(2, $summary['renamed']);
    }

    public function test_unchanged_airports_produce_no_operation_but_are_not_marked_closed(): void
    {
        $row = $this->row('AAAA', self::X);
        $unchanged = $this->airport('AAAA', self::X, collect($row)->except(['identifier', 'lat', 'lon'])->all());
        $closed = $this->airport('CLSD', self::Y);

        $changeset = $this->analyse([$row]);

        $this->assertSame(1, $changeset['summary']['unchanged']);
        $this->assertCount(1, $changeset['operations']);
        $this->assertSame(SyncOp::UNTAG, $changeset['operations'][0]['type']);
        $this->assertSame($closed->id, $changeset['operations'][0]['airport']['id']);
        $this->assertNotSame($unchanged->id, $changeset['operations'][0]['airport']['id']);
    }

    public function test_missing_sim_tag_or_changed_data_is_an_update(): void
    {
        $row = $this->row('AAAA', self::X);
        $sameData = collect($row)->except(['identifier', 'lat', 'lon'])->all();
        $this->airport('AAAA', self::X, ['sim_type' => ['fs24']] + $sameData);
        $this->airport('BBBB', self::Y, ['altitude' => 999] + $sameData);

        $changeset = $this->analyse([$row, $this->row('BBBB', self::Y)]);

        $changes = collect($changeset['operations'])
            ->where('type', SyncOp::UPDATE)
            ->mapWithKeys(fn ($op) => [$op['airport']['identifier'] => $op['changes']]);

        $this->assertSame(['sim_type'], $changes['AAAA']);
        $this->assertSame(['altitude'], $changes['BBBB']);
        $this->assertSame(0, $changeset['summary']['unchanged']);
    }

    public function test_second_identifier_at_same_field_does_not_steal_matched_airport(): void
    {
        $a = $this->airport('AAAA', self::X);

        $changeset = $this->analyse([
            $this->row('AAAA', self::X),
            $this->row('HAAA', [self::X[0] + 0.0017, self::X[1]]), // ~0.1nm away
        ]);

        $this->assertSame($a->id, $this->opOfType($changeset, SyncOp::UPDATE)['airport']['id']);
        $this->assertSame('HAAA', $this->opOfType($changeset, SyncOp::CREATE)['incoming']['identifier']);
        $this->assertCount(2, $changeset['operations']);
    }

    public function test_far_identifier_match_is_relocate_review_not_silent_move(): void
    {
        $a = $this->airport('AAAA', self::X);

        $changeset = $this->analyse([$this->row('AAAA', self::Y)]);

        $relocate = $this->opOfType($changeset, SyncOp::RELOCATE);
        $this->assertSame($a->id, $relocate['airport']['id']);
        $this->assertTrue($relocate['requires_review']);
        $this->assertNull($relocate['decision']);
        $this->assertSame(1, $changeset['summary']['unresolved']);
    }

    public function test_choosing_new_for_relocate_conflicts_with_existing_holder(): void
    {
        $this->airport('AAAA', self::X);

        $changeset = $this->analyse([$this->row('AAAA', self::Y)]);
        $changeset = $this->decide($changeset, SyncOp::RELOCATE, SyncOp::NEW);

        $this->assertSame('identifier_conflict', $changeset['conflicts'][0]['type']);
    }

    public function test_same_identifier_moved_slightly_is_relocate(): void
    {
        $a = $this->airport('AAAA', self::X);

        $changeset = $this->analyse([$this->row('AAAA', [self::X[0] + 0.025, self::X[1]])]); // ~1.5nm

        $this->assertSame($a->id, $this->opOfType($changeset, SyncOp::RELOCATE)['airport']['id']);
    }

    public function test_weak_match_is_low_confidence_review(): void
    {
        $a = $this->airport('AAAA', self::X);

        $changeset = $this->analyse([$this->row('ZZZZ', [self::X[0] + 0.0167, self::X[1]])]); // ~1nm

        $rename = $this->opOfType($changeset, SyncOp::RENAME);
        $this->assertSame($a->id, $rename['airport']['id']);
        $this->assertSame('low', $rename['confidence']);
        $this->assertNull($rename['decision']);
    }

    public function test_several_close_airports_are_ambiguous_and_all_reserved(): void
    {
        $a = $this->airport('AAAA', self::X);
        $b = $this->airport('BBBB', [self::X[0] + 0.003, self::X[1]]);

        $changeset = $this->analyse([$this->row('ZZZZ', [self::X[0] + 0.0015, self::X[1]])]);

        $ambiguous = $this->opOfType($changeset, SyncOp::AMBIGUOUS);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($ambiguous['candidates'])->pluck('id')->all());
        // Neither candidate is untagged while the admin decides
        $this->assertEmpty(collect($changeset['operations'])->where('type', SyncOp::UNTAG));

        $index = collect($changeset['operations'])->search(fn ($op) => $op['type'] === SyncOp::AMBIGUOUS);
        $changeset['operations'][$index]['decision'] = SyncOp::APPLY;
        $changeset['operations'][$index]['candidate_id'] = $b->id;
        $changeset = $this->service->refresh($changeset);

        $this->assertSame(0, $changeset['summary']['unresolved']);
        $this->executor->execute($changeset);

        $this->assertSame('ZZZZ', $b->refresh()->identifier);
        $this->assertSame('AAAA', $a->refresh()->identifier);
    }

    public function test_third_party_airports_are_never_untagged_and_hubs_need_review(): void
    {
        $this->airport('BDV0001', self::X, ['is_thirdparty' => true]);
        $hub = $this->airport('HUBB', self::Y, ['is_hub' => true]);

        $changeset = $this->analyse([]);

        $this->assertCount(1, $changeset['operations']);
        $this->assertSame(SyncOp::UNTAG_REVIEW, $changeset['operations'][0]['type']);
        $this->assertSame($hub->id, $changeset['operations'][0]['airport']['id']);
    }

    public function test_third_party_at_same_location_is_promotion_needing_decision(): void
    {
        $thirdParty = $this->airport('BDV0001', self::X, ['is_thirdparty' => true]);

        $changeset = $this->analyse([$this->row('AYTP', self::X)]);

        $promote = $this->opOfType($changeset, SyncOp::PROMOTE);
        $this->assertSame($thirdParty->id, $promote['airport']['id']);
        $this->assertNull($promote['decision']);

        $changeset = $this->decide($changeset, SyncOp::PROMOTE, SyncOp::APPLY);
        $this->executor->execute($changeset);

        $thirdParty->refresh();
        $this->assertSame('AYTP', $thirdParty->identifier);
        $this->assertFalse($thirdParty->is_thirdparty);
        $this->assertNull($thirdParty->user_id);
    }

    public function test_duplicate_identifiers_in_import(): void
    {
        $samePlace = $this->analyse([
            $this->row('CCCC', self::X, ['size' => 2]),
            $this->row('CCCC', self::X, ['size' => 4]),
        ]);
        $this->assertCount(1, $samePlace['operations']);
        $this->assertSame(4, $samePlace['operations'][0]['incoming']['size']);
        $this->assertSame('CCCC', $samePlace['merged_duplicates'][0]['identifier']);

        $farApart = $this->analyse([
            $this->row('CCCC', self::X),
            $this->row('CCCC', self::Y),
        ]);
        $this->assertSame('identifier_conflict', $farApart['conflicts'][0]['type']);
        $this->expectConflictOnExecute($farApart);
        $this->assertSame(0, Airport::count());
    }

    public function test_rows_without_coordinates_are_reported_not_imported(): void
    {
        $changeset = $this->analyse([$this->row('NOLL', [null, null])]);

        $this->assertEmpty($changeset['operations']);
        $this->assertSame('NOLL', $changeset['invalid_rows'][0]['identifier']);
    }

    public function test_execute_aborts_when_airport_changed_after_analysis(): void
    {
        $a = $this->airport('AAAA', self::X);

        $changeset = $this->analyse([$this->row('ZZZZ', self::X)]);

        $this->travel(5)->seconds();
        $a->update(['name' => 'Edited meanwhile']);

        $this->expectConflictOnExecute($changeset);
        $this->assertSame('AAAA', $a->refresh()->identifier);
    }

    public function test_active_pirep_blocks_rename(): void
    {
        $a = $this->airport('AAAA', self::X);
        Pirep::factory()->inProgress()->create(['departure_airport_id' => $a->id]);

        $changeset = $this->analyse([$this->row('ZZZZ', self::X)]);

        $this->assertSame('active_pireps', $changeset['conflicts'][0]['type']);
    }

    private function analyse(array $rows): array
    {
        return $this->service->analyse(new Collection($rows), SimType::MSFS2020);
    }

    private function decide(array $changeset, string $type, string $decision): array
    {
        $index = collect($changeset['operations'])->search(fn ($op) => $op['type'] === $type);
        $changeset['operations'][$index]['decision'] = $decision;

        return $this->service->refresh($changeset);
    }

    private function decideAll(array $changeset, string $type, string $decision): array
    {
        foreach ($changeset['operations'] as &$op) {
            if ($op['type'] === $type) {
                $op['decision'] = $decision;
            }
        }
        unset($op);

        return $this->service->refresh($changeset);
    }

    private function opOfType(array $changeset, string $type): array
    {
        $op = collect($changeset['operations'])->firstWhere('type', $type);
        $this->assertNotNull($op, "No {$type} operation");

        return $op;
    }

    private function expectConflictOnExecute(array $changeset): void
    {
        try {
            $this->executor->execute($changeset, true);
            $this->fail('Expected execution to be refused');
        } catch (AirportSyncConflictException) {
            $this->addToAssertionCount(1);
        }
    }

    private function airport(string $identifier, array $at, array $attributes = []): Airport
    {
        return Airport::factory()->create(array_merge([
            'identifier' => $identifier,
            'lat' => $at[0],
            'lon' => $at[1],
            'sim_type' => ['fs20'],
            'is_thirdparty' => false,
        ], $attributes));
    }

    private function row(string $identifier, array $at, array $attributes = []): array
    {
        return array_merge([
            'identifier' => $identifier,
            'name' => "{$identifier} Airport",
            'location' => null,
            'country' => 'Papua New Guinea',
            'country_code' => 'PG',
            'lat' => $at[0],
            'lon' => $at[1],
            'altitude' => 100,
            'magnetic_variance' => 1,
            'longest_runway_length' => 1000,
            'longest_runway_surface' => 'A',
            'has_avgas' => true,
            'has_jetfuel' => false,
            'size' => 3,
        ], $attributes);
    }
}
