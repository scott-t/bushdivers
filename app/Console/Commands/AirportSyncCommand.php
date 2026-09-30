<?php

namespace App\Console\Commands;

use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncConflictException;
use App\Services\AirportSync\AirportSyncExecutor;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\LnmCsvParser;
use App\Services\AirportSync\SyncOp;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;

class AirportSyncCommand extends Command
{
    protected $signature = 'airports:sync
        {file : Path to LittleNavMap CSV export}
        {--sim=fs20 : Sim type value (fs20|fs24)}
        {--report= : Write the full changeset as JSON to this path}
        {--execute : Apply changes (dry-run otherwise)}
        {--skip-review : With --execute, treat undecided review items as "ignore"}
        {--deactivate : Include untags (removing this sim from airports missing in the export)}';

    protected $description = 'Analyse airport sync from a LittleNavMap CSV file (dry-run by default, use --execute to apply)';

    public function __construct(
        private readonly LnmCsvParser $parser,
        private readonly AirportSyncService $syncService,
        private readonly AirportSyncExecutor $executor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');
        $simTypeValue = (string) $this->option('sim');

        if (! file_exists($filePath)) {
            $this->error("File does not exist: {$filePath}");

            return self::FAILURE;
        }

        if (validator(['sim' => $simTypeValue], ['sim' => ['required', Rule::enum(SimType::class)]])->fails()) {
            $this->error('The --sim option must be a valid SimType value (fs20 or fs24).');

            return self::FAILURE;
        }

        $changeset = $this->syncService->analyse($this->parser->parse($filePath), SimType::from($simTypeValue));

        $this->printSummary($changeset);

        if ($report = $this->option('report')) {
            file_put_contents($report, json_encode($changeset, JSON_PRETTY_PRINT));
            $this->info("Changeset written to {$report}");
        }

        if (! $this->option('execute')) {
            $this->info('Dry run complete. No changes applied.');

            return self::SUCCESS;
        }

        if ($changeset['summary']['unresolved'] > 0) {
            if (! $this->option('skip-review')) {
                $this->error("{$changeset['summary']['unresolved']} review item(s) need a decision. Use the admin UI, or --skip-review to ignore them.");

                return self::FAILURE;
            }

            foreach ($changeset['operations'] as &$op) {
                if (SyncOp::isUnresolved($op)) {
                    $op['decision'] = SyncOp::IGNORE;
                }
            }
            unset($op);

            $changeset = $this->syncService->refresh($changeset);
        }

        if ($changeset['conflicts']) {
            $this->printConflicts($changeset['conflicts']);
            $this->error('Resolve the conflicts above in the admin UI before executing.');

            return self::FAILURE;
        }

        if (! $this->confirm('Execute sync changes now? There is no undo, so make sure you have a database backup.')) {
            $this->warn('Execution cancelled.');

            return self::SUCCESS;
        }

        try {
            $summary = $this->executor->execute($changeset, (bool) $this->option('deactivate'));
        } catch (AirportSyncConflictException $e) {
            $this->printConflicts($e->conflicts);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Action', 'Count'], collect($summary)->map(fn ($count, $action) => [$action, $count])->values()->all());

        return self::SUCCESS;
    }

    private function printSummary(array $changeset): void
    {
        $summary = $changeset['summary'];

        $this->table(['Metric', 'Count'], [
            ['Incoming (valid)', $summary['total_incoming']],
            ['Invalid rows skipped', $summary['invalid_rows']],
            ['Unchanged (no operation)', $summary['unchanged']],
            ...collect($summary['by_type'])->map(fn ($count, $type) => ["Type: {$type}", $count])->values()->all(),
            ['Review items', $summary['review_items']],
            ['Unresolved', $summary['unresolved']],
            ['Conflicts', $summary['conflicts']],
        ]);

        $this->printConflicts($changeset['conflicts']);
    }

    private function printConflicts(array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            $this->warn("[{$conflict['type']}] {$conflict['message']}");
        }
    }
}
