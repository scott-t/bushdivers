<?php

namespace App\Console\Commands;

use App\Models\Enums\PirepState;
use App\Models\Enums\SimType;
use App\Models\Pirep;
use App\Services\Airports\AirportCsvRow;
use App\Services\Airports\Sync\AirportSyncPlan;
use App\Services\Airports\Sync\AirportSyncPlanner;
use App\Services\Airports\Sync\ApplyAirportSyncPlan;
use App\Services\Airports\Sync\ExistingAirport;
use App\Services\Airports\Sync\SyncRow;
use App\Services\CsvBulkUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use League\Csv\Writer;

class SyncSimAirports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'va:sync-sim-airports
        {file : CSV of every airport in the sim, in the airport bulk upload format}
        {--sim= : The sim the CSV is for (fs20 or fs24)}
        {--dry-run : Report the changes without applying them}
        {--report= : Path to write the change report CSV to}
        {--match-distance=1 : Max distance (nm) for a CSV airport to be treated as the same physical airport}
        {--min-separation=2 : Min distance (nm) from other airports for a new airport to be added}
        {--force : Apply the changes without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronises base airports with a sim\'s airport list, renaming, closing and adding airports as required';

    /**
     * Execute the console command.
     */
    public function handle(CsvBulkUploadService $csvService, ApplyAirportSyncPlan $applyPlan): int
    {
        $sim = SimType::tryFrom((string) $this->option('sim'));
        if (!$sim) {
            $this->error('--sim must be one of: ' . implode(', ', array_map(fn ($type) => $type->value, SimType::cases())));
            return self::FAILURE;
        }

        $distances = Validator::make($this->options(), [
            // Distances are rounded to 0.1nm; much beyond a few nm risks pairing up genuinely different airports
            'match-distance' => ['required', 'numeric', 'between:0.1,5'],
            // Must cover the match distance, or a row that lost a match could be added right beside that airport
            'min-separation' => ['required', 'numeric', 'between:0.1,10', 'gte:match-distance'],
        ]);
        if ($distances->fails()) {
            foreach ($distances->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $file = $this->argument('file');
        if (!is_readable($file)) {
            $this->error("Cannot read {$file}");
            return self::FAILURE;
        }

        // Every airport is held in memory while planning, which needs more than the usual CLI limit
        $this->raiseMemoryLimit('512M');

        $rows = $this->readRows($csvService, $file);
        if ($rows === null) {
            return self::FAILURE;
        }

        $this->info('Read ' . count($rows) . " {$sim->label()} airports, planning changes...");

        $existing = DB::table('airports')
            ->select(ExistingAirport::COLUMNS)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => ExistingAirport::fromRow($row));

        $activeAirportIds = Pirep::whereIn('state', [PirepState::DISPATCH, PirepState::IN_PROGRESS])
            ->get(['departure_airport_id', 'arrival_airport_id'])
            ->flatMap(fn ($pirep) => [$pirep->departure_airport_id, $pirep->arrival_airport_id])
            ->filter()
            ->unique()
            ->all();

        $planner = new AirportSyncPlanner(
            (float) $distances->validated()['match-distance'],
            (float) $distances->validated()['min-separation'],
        );
        $plan = $planner->plan($sim, $rows, $existing, $activeAirportIds);

        $this->printSummary($plan);

        $reportPath = $this->writeReport($plan, $sim);
        $this->info("Full report written to {$reportPath}");

        if ($this->option('dry-run')) {
            $this->info('Dry run, no changes applied.');
            return self::SUCCESS;
        }

        if ($plan->isEmpty()) {
            $this->info('Nothing to change.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Apply these changes?')) {
            $this->info('No changes applied.');
            return self::SUCCESS;
        }

        $applyPlan->execute($plan);
        $this->info('Changes applied.');

        return self::SUCCESS;
    }

    private function raiseMemoryLimit(string $limit): void
    {
        $current = ini_get('memory_limit');
        if ($current !== '-1' && ini_parse_quantity($current) < ini_parse_quantity($limit)) {
            ini_set('memory_limit', $limit);
        }
    }

    /**
     * @return list<SyncRow>|null Null when the file is invalid
     */
    private function readRows(CsvBulkUploadService $csvService, string $file): ?array
    {
        $seen = [];

        try {
            $results = $csvService->processFile(
                $file,
                function ($record, $rowNumber) use (&$seen) {
                    $rules = [
                        'identifier' => [
                            'required',
                            'string',
                            'max:12', // leaves space for [X] when closed
                            'alpha_num',
                            function ($attribute, $value, $fail) use (&$seen) {
                                $key = strtoupper(trim($value));
                                if (isset($seen[$key])) {
                                    $fail("The identifier {$key} is used multiple times in this file.");
                                }
                                $seen[$key] = true;
                            },
                        ],
                    ] + AirportCsvRow::rules();

                    $validator = Validator::make($record, $rules);
                    if ($validator->fails()) {
                        return [
                            'success' => false,
                            'errors' => [['row' => $rowNumber, 'message' => implode(', ', $validator->errors()->all())]],
                        ];
                    }

                    return ['success' => true, 'data' => SyncRow::fromCsv($rowNumber, $validator->validated())];
                },
                [],
                AirportCsvRow::REQUIRED_COLUMNS
            );
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return null;
        }

        if ($results['errors']) {
            $this->error(count($results['errors']) . ' invalid rows, no changes made:');
            $this->table(['Row', 'Error'], array_map(fn ($error) => [$error['row'], $error['message']], array_slice($results['errors'], 0, 50)));
            return null;
        }

        return $results['data'];
    }

    private function printSummary(AirportSyncPlan $plan): void
    {
        $this->table(
            ['Action', 'Count'],
            collect($plan->summary())->map(fn ($count, $action) => [$action, $count])->values()->all()
        );

        if ($plan->conflicts) {
            $this->warn('Conflicts (not applied):');
            $this->table(
                ['Airport', 'Identifier', 'Wanted', 'Reason'],
                array_map(fn ($issue) => [$issue['airport_id'] ?? 'new', $issue['identifier'], $issue['wanted'], $issue['reason']], array_slice($plan->conflicts, 0, 20))
            );
        }

        if ($plan->warnings) {
            $this->warn('Warnings:');
            $this->table(
                ['Airport', 'Identifier', 'Warning'],
                array_map(fn ($warning) => [$warning['airport_id'], $warning['identifier'], $warning['message']], array_slice($plan->warnings, 0, 20))
            );
        }
    }

    private function writeReport(AirportSyncPlan $plan, SimType $sim): string
    {
        $path = $this->option('report') ?: storage_path('app/airport-sync/' . $sim->value . '-' . now()->format('Ymd-His') . '.csv');
        File::ensureDirectoryExists(dirname($path));

        $writer = Writer::from($path, 'w');
        $writer->insertOne(AirportSyncPlan::REPORT_COLUMNS);
        $writer->insertAll(array_map('array_values', $plan->reportRows()));

        return $path;
    }
}
