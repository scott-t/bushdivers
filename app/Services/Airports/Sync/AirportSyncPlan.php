<?php

namespace App\Services\Airports\Sync;

use App\Models\Enums\SimType;

/**
 * @phpstan-type SyncIssue array{row: int|null, airport_id: int|null, identifier: string, wanted: string|null, name: string, distance: float|null, reason: string}
 * @phpstan-type SyncWarning array{airport_id: int, identifier: string, message: string}
 * @phpstan-type ReportRow array{action: string, airport_id: int|null, old_identifier: string|null, new_identifier: string|null, name: string, distance_nm: float|null, note: string}
 */
class AirportSyncPlan
{
    public const array REPORT_COLUMNS = ['action', 'airport_id', 'old_identifier', 'new_identifier', 'name', 'distance_nm', 'note'];

    /**
     * Existing airports that will change, keyed by airport id
     * @var array<int, AirportChange>
     */
    public array $changes = [];

    /**
     * CSV rows to be added as new airports
     * @var list<SyncRow>
     */
    public array $additions = [];

    /**
     * CSV rows not added because another airport is too close
     * @var list<SyncIssue>
     */
    public array $skipped = [];

    /**
     * Changes not applied because the identifier is held by another airport
     * @var list<SyncIssue>
     */
    public array $conflicts = [];

    /**
     * Changes that will be applied but may need attention
     * @var list<SyncWarning>
     */
    public array $warnings = [];

    /**
     * Matched airports that already reflect the CSV
     */
    public int $unchanged = 0;

    public function __construct(public readonly SimType $sim)
    {
    }

    public function isEmpty(): bool
    {
        return empty($this->changes) && empty($this->additions);
    }

    /**
     * Count of each action
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $summary = array_fill_keys(['rename', 'close', 'reopen', 'promote', 'tag', 'untag'], 0);

        foreach ($this->changes as $change) {
            foreach ($change->actions() as $action) {
                $summary[$action]++;
            }
        }

        return $summary + [
            'add' => count($this->additions),
            'unchanged' => $this->unchanged,
            'skip' => count($this->skipped),
            'conflict' => count($this->conflicts),
            'warning' => count($this->warnings),
        ];
    }

    /**
     * @return list<ReportRow>
     */
    public function reportRows(): array
    {
        $warnings = [];
        foreach ($this->warnings as $warning) {
            $warnings[$warning['airport_id']][] = $warning['message'];
        }

        $rows = [];

        foreach ($this->changes as $change) {
            $rows[] = [
                'action' => implode('+', $change->actions()),
                'airport_id' => $change->airport->id,
                'old_identifier' => $change->airport->identifier,
                'new_identifier' => $change->identifier,
                'name' => $change->row->name ?? $change->airport->name,
                'distance_nm' => $change->distance,
                'note' => implode('; ', $warnings[$change->airport->id] ?? []),
            ];
        }

        foreach ($this->additions as $row) {
            $rows[] = [
                'action' => 'add',
                'airport_id' => null,
                'old_identifier' => null,
                'new_identifier' => $row->identifier,
                'name' => $row->name,
                'distance_nm' => null,
                'note' => "CSV row {$row->row}",
            ];
        }

        foreach (['skip' => $this->skipped, 'conflict' => $this->conflicts] as $action => $issues) {
            foreach ($issues as $issue) {
                $rows[] = [
                    'action' => $action,
                    'airport_id' => $issue['airport_id'],
                    'old_identifier' => $issue['airport_id'] ? $issue['identifier'] : null,
                    'new_identifier' => $issue['wanted'],
                    'name' => $issue['name'],
                    'distance_nm' => $issue['distance'],
                    'note' => $issue['reason'] . ($issue['row'] ? " (CSV row {$issue['row']})" : ''),
                ];
            }
        }

        return $rows;
    }
}
