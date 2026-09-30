<?php

namespace Tests\Unit\Services\AirportSync;

use App\Services\AirportSync\IdentifierResolver;
use App\Services\AirportSync\SpatialIndex;
use App\Services\AirportSync\SyncOp;
use PHPUnit\Framework\TestCase;

class IdentifierResolverTest extends TestCase
{
    private const CURRENT = [1 => 'AAAA', 2 => 'BBBB', 3 => 'CCCC'];

    public function test_swap_and_cycle_are_not_conflicts(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([
            $this->modify('A', 1, 'BBBB'),
            $this->modify('B', 2, 'CCCC'),
            $this->modify('C', 3, 'AAAA'),
        ]), self::CURRENT);

        $this->assertEmpty($plan['conflicts']);
        $this->assertSame([1 => 'BBBB', 2 => 'CCCC', 3 => 'AAAA'], $plan['identifier_changes']);
    }

    public function test_rename_onto_untouched_holder_conflicts_until_overridden(): void
    {
        $changeset = $this->changeset([$this->modify('A', 1, 'BBBB')]);

        $plan = (new IdentifierResolver())->plan($changeset, self::CURRENT);
        $this->assertSame('identifier_conflict', $plan['conflicts'][0]['type']);
        $this->assertEqualsCanonicalizing([1, 2], array_column($plan['conflicts'][0]['parties'], 'airport_id'));

        $changeset['identifier_overrides'] = [2 => 'BBBB-OLD'];
        $plan = (new IdentifierResolver())->plan($changeset, self::CURRENT);
        $this->assertEmpty($plan['conflicts']);
    }

    public function test_create_onto_existing_identifier_conflicts(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([
            ['id' => 'N', 'type' => SyncOp::CREATE, 'decision' => SyncOp::APPLY, 'requires_review' => false, 'airport' => null, 'incoming' => ['identifier' => 'cccc']],
        ]), self::CURRENT);

        $this->assertSame('identifier_conflict', $plan['conflicts'][0]['type']);
        $this->assertSame('CCCC', $plan['conflicts'][0]['identifier']);
    }

    public function test_same_airport_claimed_twice(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([
            $this->modify('A', 1, 'XXXX'),
            $this->modify('B', 1, 'YYYY'),
        ]), self::CURRENT);

        $this->assertContains('airport_claimed_twice', array_column($plan['conflicts'], 'type'));
    }

    public function test_locked_airport_cannot_change_identifier(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([$this->modify('A', 1, 'XXXX')]), self::CURRENT, [1 => true]);

        $this->assertSame('active_pireps', $plan['conflicts'][0]['type']);
    }

    public function test_unchanged_identifier_is_not_a_rename(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([$this->modify('A', 1, 'aaaa')]), self::CURRENT, [1 => true]);

        $this->assertEmpty($plan['conflicts']);
        $this->assertEmpty($plan['identifier_changes']);
        $this->assertArrayHasKey(1, $plan['modify']);
    }

    public function test_invalid_identifier(): void
    {
        $plan = (new IdentifierResolver())->plan($this->changeset([$this->modify('A', 1, 'WAY-TOO-LONG-IDENT')]), self::CURRENT);

        $this->assertSame('invalid_identifier', $plan['conflicts'][0]['type']);
    }

    public function test_undecided_review_items_are_counted_and_have_no_effect(): void
    {
        $op = $this->modify('A', 1, 'BBBB');
        $op['requires_review'] = true;
        $op['decision'] = null;

        $plan = (new IdentifierResolver())->plan($this->changeset([$op]), self::CURRENT);

        $this->assertSame(1, $plan['unresolved']);
        $this->assertEmpty($plan['conflicts']);
        $this->assertEmpty($plan['modify']);
    }

    public function test_spatial_index_finds_neighbours_across_antimeridian(): void
    {
        $index = new SpatialIndex();
        $index->add(1, -17.0, 179.99);
        $index->add(2, -17.0, 170.0);

        $this->assertSame([1], array_keys($index->within(-17.0, -179.99, 2.0)));
    }

    private function changeset(array $operations): array
    {
        return ['operations' => $operations, 'identifier_overrides' => []];
    }

    private function modify(string $id, int $airportId, string $identifier): array
    {
        return [
            'id' => $id,
            'type' => SyncOp::RENAME,
            'requires_review' => true,
            'decision' => SyncOp::APPLY,
            'airport' => ['id' => $airportId],
            'incoming' => ['identifier' => $identifier],
        ];
    }
}
