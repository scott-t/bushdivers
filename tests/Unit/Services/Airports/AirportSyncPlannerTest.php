<?php

namespace Tests\Unit\Services\Airports;

use App\Models\Enums\SimType;
use App\Services\Airports\Sync\AirportSyncPlan;
use App\Services\Airports\Sync\AirportSyncPlanner;
use App\Services\Airports\Sync\ExistingAirport;
use App\Services\Airports\Sync\SyncRow;
use Tests\TestCase;

class AirportSyncPlannerTest extends TestCase
{
    private const float LAT = -6.0;
    private const float LON = 143.0;

    private int $rowNumber = 1;

    /**
     * Latitude the given nm north of a base point (each base point is a degree apart)
     */
    private function lat(float $nm = 0, int $point = 0): float
    {
        return self::LAT + $point + $nm / 60;
    }

    /**
     * @param list<string> $sims
     * @param array<string, mixed> $extra
     */
    private function airport(int $id, string $identifier, float $lat, float $lon = self::LON, array $sims = ['fs20', 'fs24'], array $extra = []): ExistingAirport
    {
        return new ExistingAirport(...array_merge([
            'id' => $id,
            'identifier' => $identifier,
            'name' => "Airport {$identifier}",
            'lat' => $lat,
            'lon' => $lon,
            'simTypes' => $sims,
        ], $extra));
    }

    private function row(string $identifier, float $lat, float $lon = self::LON): SyncRow
    {
        return new SyncRow($this->rowNumber++, $identifier, "CSV {$identifier}", $lat, $lon);
    }

    /**
     * @param list<SyncRow> $rows
     * @param list<ExistingAirport> $existing
     * @param array<int> $active
     */
    private function plan(array $rows, array $existing, array $active = []): AirportSyncPlan
    {
        return (new AirportSyncPlanner())->plan(SimType::MSFS2024, $rows, $existing, $active);
    }

    public function test_identifier_match_at_same_location_is_unchanged(): void
    {
        $plan = $this->plan([$this->row('AAAA', $this->lat())], [$this->airport(1, 'AAAA', $this->lat())]);

        $this->assertEmpty($plan->changes);
        $this->assertEmpty($plan->additions);
        $this->assertEquals(1, $plan->unchanged);
    }

    public function test_identifier_match_adds_sim(): void
    {
        $plan = $this->plan([$this->row('AAAA', $this->lat())], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20'])]);

        $this->assertEquals(['fs20', 'fs24'], $plan->changes[1]->simTypes);
        $this->assertEquals(['tag'], $plan->changes[1]->actions());
    }

    public function test_identifier_match_takes_priority_over_closer_airport(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat(0.5))],
            [$this->airport(1, 'AAAA', $this->lat()), $this->airport(2, 'ZZZZ', $this->lat(0.4))]
        );

        $this->assertArrayNotHasKey(1, $plan->changes);
        $this->assertEquals(['untag'], $plan->changes[2]->actions());
    }

    public function test_nearby_airport_is_renamed(): void
    {
        $plan = $this->plan([$this->row('BBBB', $this->lat(0.8))], [$this->airport(1, 'AAAA', $this->lat())]);

        $this->assertEquals('BBBB', $plan->changes[1]->identifier);
        $this->assertEquals(['rename'], $plan->changes[1]->actions());
        $this->assertEquals(0.8, $plan->changes[1]->distance);
        $this->assertEmpty($plan->additions);
    }

    public function test_airport_beyond_match_distance_is_not_renamed(): void
    {
        $plan = $this->plan([$this->row('BBBB', $this->lat(1.2))], [$this->airport(1, 'AAAA', $this->lat())]);

        $this->assertEquals('AAAA', $plan->changes[1]->identifier);
        $this->assertEquals(['untag'], $plan->changes[1]->actions());
        $this->assertEmpty($plan->additions);
        $this->assertCount(1, $plan->skipped);
        $this->assertStringContainsString('AAAA', $plan->skipped[0]['reason']);
    }

    public function test_identifiers_can_be_swapped(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat(0, 0)), $this->row('AAAA', $this->lat(0, 1))],
            [$this->airport(1, 'AAAA', $this->lat(0, 0)), $this->airport(2, 'BBBB', $this->lat(0, 1))]
        );

        $this->assertEquals('BBBB', $plan->changes[1]->identifier);
        $this->assertEquals('AAAA', $plan->changes[2]->identifier);
        $this->assertEmpty($plan->conflicts);
    }

    public function test_identifiers_can_be_chained(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat(0, 0)), $this->row('CCCC', $this->lat(0, 1))],
            [$this->airport(1, 'AAAA', $this->lat(0, 0)), $this->airport(2, 'BBBB', $this->lat(0, 1))]
        );

        $this->assertEquals('BBBB', $plan->changes[1]->identifier);
        $this->assertEquals('CCCC', $plan->changes[2]->identifier);
        $this->assertEmpty($plan->conflicts);
    }

    public function test_missing_airport_in_other_sim_loses_tag_only(): void
    {
        $plan = $this->plan([], [$this->airport(1, 'AAAA', $this->lat())]);

        $this->assertEquals(['fs20'], $plan->changes[1]->simTypes);
        $this->assertFalse($plan->changes[1]->closed);
        $this->assertEquals('AAAA', $plan->changes[1]->identifier);
        $this->assertEquals(['untag'], $plan->changes[1]->actions());
    }

    public function test_missing_airport_in_no_other_sim_is_closed(): void
    {
        $plan = $this->plan([], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs24'])]);

        $this->assertEquals([], $plan->changes[1]->simTypes);
        $this->assertTrue($plan->changes[1]->closed);
        $this->assertEquals('[X]AAAA', $plan->changes[1]->identifier);
        $this->assertEquals(['close'], $plan->changes[1]->actions());
    }

    public function test_closed_identifier_is_deduplicated(): void
    {
        $plan = $this->plan([], [
            $this->airport(1, '[X]AAAA', $this->lat(0, 1), sims: [], extra: ['closed' => true]),
            $this->airport(2, 'AAAA', $this->lat(), sims: ['fs24']),
        ]);

        $this->assertArrayNotHasKey(1, $plan->changes);
        $this->assertEquals('[X]AAAA-2', $plan->changes[2]->identifier);
    }

    public function test_airport_not_in_sim_is_ignored(): void
    {
        $plan = $this->plan([], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20'])]);

        $this->assertTrue($plan->isEmpty());
    }

    public function test_closed_airport_is_reopened(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat())],
            [$this->airport(1, '[X]AAAA', $this->lat(), sims: [], extra: ['closed' => true])]
        );

        $change = $plan->changes[1];
        $this->assertFalse($change->closed);
        $this->assertEquals('AAAA', $change->identifier);
        $this->assertEquals(['fs24'], $change->simTypes);
        $this->assertEquals(['reopen', 'tag'], $change->actions());
    }

    public function test_closed_airport_reopened_under_new_identifier(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat(0.5))],
            [$this->airport(1, '[X]AAAA-2', $this->lat(), sims: [], extra: ['closed' => true])]
        );

        $this->assertEquals('BBBB', $plan->changes[1]->identifier);
        $this->assertEquals(['reopen', 'rename', 'tag'], $plan->changes[1]->actions());
    }

    public function test_third_party_airport_is_promoted(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat(0.5))],
            [$this->airport(1, 'TP01', $this->lat(), extra: ['isThirdParty' => true])]
        );

        $this->assertFalse($plan->changes[1]->isThirdParty);
        $this->assertEquals('AAAA', $plan->changes[1]->identifier);
        $this->assertEquals(['promote', 'rename'], $plan->changes[1]->actions());
    }

    public function test_base_airport_preferred_over_third_party(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat(0.5))],
            [
                $this->airport(1, 'TP01', $this->lat(), extra: ['isThirdParty' => true]),
                $this->airport(2, 'BASE', $this->lat(), sims: ['fs20']),
            ]
        );

        $this->assertArrayNotHasKey(1, $plan->changes);
        $this->assertEquals('AAAA', $plan->changes[2]->identifier);
    }

    public function test_unmatched_third_party_airport_is_left_alone(): void
    {
        $plan = $this->plan([], [$this->airport(1, 'TP01', $this->lat(), extra: ['isThirdParty' => true])]);

        $this->assertTrue($plan->isEmpty());
    }

    public function test_user_airport_is_not_matched(): void
    {
        $plan = $this->plan([$this->row('AAAA', $this->lat())], [$this->airport(1, 'CAMP', $this->lat(), extra: ['userId' => 5])]);

        $this->assertEmpty($plan->changes);
        $this->assertEquals('AAAA', $plan->additions[0]->identifier);
    }

    public function test_new_airport_is_added(): void
    {
        $plan = $this->plan([$this->row('NEWW', $this->lat(0, 1))], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20'])]);

        $this->assertCount(1, $plan->additions);
        $this->assertEquals('NEWW', $plan->additions[0]->identifier);
        $this->assertEquals(1, $plan->summary()['add']);
    }

    public function test_new_airport_too_close_to_open_airport_is_skipped(): void
    {
        $plan = $this->plan([$this->row('NEWW', $this->lat(1.5))], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20'])]);

        $this->assertEmpty($plan->additions);
        $this->assertCount(1, $plan->skipped);
        $this->assertEquals('NEWW', $plan->skipped[0]['wanted']);
    }

    public function test_new_airport_near_closing_airport_is_added(): void
    {
        $plan = $this->plan([$this->row('NEWW', $this->lat(1.5))], [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs24'])]);

        $this->assertTrue($plan->changes[1]->closed);
        $this->assertCount(1, $plan->additions);
    }

    public function test_new_airports_too_close_to_each_other(): void
    {
        $plan = $this->plan([$this->row('NEW1', $this->lat()), $this->row('NEW2', $this->lat(1.5))], []);

        $this->assertCount(1, $plan->additions);
        $this->assertEquals('NEW1', $plan->additions[0]->identifier);
        $this->assertEquals('NEW2', $plan->skipped[0]['wanted']);
    }

    public function test_closest_row_wins_when_several_are_near_one_airport(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat(0.6)), $this->row('CCCC', $this->lat(0.3))],
            [$this->airport(1, 'AAAA', $this->lat())]
        );

        $this->assertEquals('CCCC', $plan->changes[1]->identifier);
        $this->assertEquals('BBBB', $plan->skipped[0]['wanted']);
    }

    public function test_rename_conflicting_with_user_airport_is_not_applied(): void
    {
        $plan = $this->plan(
            [$this->row('CAMP', $this->lat())],
            [$this->airport(1, 'AAAA', $this->lat()), $this->airport(2, 'CAMP', $this->lat(0, 1), extra: ['userId' => 5])]
        );

        $this->assertEmpty($plan->changes);
        $this->assertEmpty($plan->additions);
        $this->assertCount(1, $plan->conflicts);
        $this->assertEquals(1, $plan->conflicts[0]['airport_id']);
        $this->assertEquals('CAMP', $plan->conflicts[0]['wanted']);
    }

    public function test_conflicting_rename_still_adds_sim(): void
    {
        $plan = $this->plan(
            [$this->row('CAMP', $this->lat())],
            [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20']), $this->airport(2, 'CAMP', $this->lat(0, 1), extra: ['userId' => 5])]
        );

        $this->assertEquals('AAAA', $plan->changes[1]->identifier);
        $this->assertEquals(['tag'], $plan->changes[1]->actions());
    }

    public function test_conflicts_cascade(): void
    {
        // AAAA can't become CCCC, so keeps AAAA, so BBBB can't become AAAA
        $plan = $this->plan(
            [$this->row('CCCC', $this->lat(0, 0)), $this->row('AAAA', $this->lat(0, 1))],
            [
                $this->airport(1, 'AAAA', $this->lat(0, 0)),
                $this->airport(2, 'BBBB', $this->lat(0, 1)),
                $this->airport(3, 'CCCC', $this->lat(0, 2), extra: ['userId' => 5]),
            ]
        );

        $this->assertEmpty($plan->changes);
        $this->assertCount(2, $plan->conflicts);
    }

    public function test_addition_conflicting_with_other_sim_airport_is_not_applied(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat(0, 1))],
            [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs20'])]
        );

        $this->assertEmpty($plan->additions);
        $this->assertCount(1, $plan->conflicts);
        $this->assertNull($plan->conflicts[0]['airport_id']);
    }

    public function test_addition_can_take_identifier_of_closing_airport(): void
    {
        $plan = $this->plan(
            [$this->row('AAAA', $this->lat(0, 1))],
            [$this->airport(1, 'AAAA', $this->lat(), sims: ['fs24'])]
        );

        $this->assertEquals('[X]AAAA', $plan->changes[1]->identifier);
        $this->assertEquals('AAAA', $plan->additions[0]->identifier);
        $this->assertEmpty($plan->conflicts);
    }

    public function test_matches_across_antimeridian(): void
    {
        $plan = $this->plan([$this->row('BBBB', 0, -179.995)], [$this->airport(1, 'AAAA', 0, 179.995)]);

        $this->assertEquals('BBBB', $plan->changes[1]->identifier);
    }

    public function test_warnings_for_hubs_active_airports_and_shared_renames(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat())],
            [$this->airport(1, 'AAAA', $this->lat(), extra: ['isHub' => true])],
            [1]
        );

        $messages = array_column($plan->warnings, 'message');
        $this->assertContains('Hub airport renamed', $messages);
        $this->assertContains('Airport with in-progress PIREPs renamed', $messages);
        $this->assertContains('Rename also applies to MSFS 2020', $messages);
    }

    public function test_report_rows(): void
    {
        $plan = $this->plan(
            [$this->row('BBBB', $this->lat()), $this->row('NEWW', $this->lat(0, 1))],
            [$this->airport(1, 'AAAA', $this->lat())]
        );

        $rows = collect($plan->reportRows())->keyBy('action');
        $this->assertEquals('AAAA', $rows['rename']['old_identifier']);
        $this->assertEquals('BBBB', $rows['rename']['new_identifier']);
        $this->assertEquals('NEWW', $rows['add']['new_identifier']);
        $this->assertEquals(AirportSyncPlan::REPORT_COLUMNS, array_keys($rows['add']));
    }
}
