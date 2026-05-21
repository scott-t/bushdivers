<?php

namespace App\Console\Commands;

use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncExecutor;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\LnmCsvParser;
use Illuminate\Console\Command;
use Illuminate\Validation\Rule;

class AirportSyncCommand extends Command
{
    protected $signature = 'airports:sync
        {file : Path to LittleNavMap CSV export}
        {--sim=fs20 : Sim type value (fs20|fs24)}
        {--dry-run : Analyse only without applying changes}
        {--execute : Apply changes immediately}
        {--deactivate : Include deactivations while executing}';

    protected $description = 'Analyse and optionally execute airport sync from a LittleNavMap CSV file';

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

        $validation = validator(
            ['sim' => $simTypeValue],
            ['sim' => ['required', Rule::enum(SimType::class)]]
        );

        if (! file_exists($filePath)) {
            $this->error("File does not exist: {$filePath}");

            return self::FAILURE;
        }

        if ($validation->fails()) {
            $this->error('The --sim option must be a valid SimType value (fs20 or fs24).');

            return self::FAILURE;
        }

        $simType = SimType::from($simTypeValue);
        $results = $this->syncService->analyse($this->parser->parse($filePath), $simType);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Incoming', $results['summary']['total_incoming']],
                ['Auto updates', $results['summary']['auto_updates']],
                ['New airports', $results['summary']['new_airports']],
                ['Review items', $results['summary']['review_items']],
                ['Deactivations', $results['summary']['deactivations']],
            ]
        );

        if ($this->option('dry-run') || ! $this->option('execute')) {
            $this->info('Dry run complete. No changes applied.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Execute sync changes now?')) {
            $this->warn('Execution cancelled.');

            return self::SUCCESS;
        }

        $summary = $this->executor->execute($results, $simType, (bool) $this->option('deactivate'));

        $this->table(
            ['Action', 'Count'],
            [
                ['Auto updated', $summary['auto_updated'] ?? 0],
                ['New airports', $summary['new_airports'] ?? 0],
                ['Renamed', $summary['renamed'] ?? 0],
                ['Promoted', $summary['promoted'] ?? 0],
                ['Ignored', $summary['ignored'] ?? 0],
                ['Deactivated', $summary['deactivated'] ?? 0],
            ]
        );

        return self::SUCCESS;
    }
}
