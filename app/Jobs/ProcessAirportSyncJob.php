<?php

namespace App\Jobs;

use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\AirportSyncSessionManager;
use App\Services\AirportSync\LnmCsvParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAirportSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $sessionId)
    {
    }

    public function handle(
        AirportSyncSessionManager $sessionManager,
        LnmCsvParser $parser,
        AirportSyncService $airportSyncService
    ): void {
        $session = $sessionManager->get($this->sessionId);

        if (! $session) {
            return;
        }

        try {
            $sessionManager->setStatus($this->sessionId, 'processing');

            $records = $parser->parse($session['file_path']);
            $simType = SimType::from($session['sim_type']);

            $results = $airportSyncService->analyse(
                $records,
                $simType,
                fn (int $current, int $total) => $sessionManager->setProgress($this->sessionId, $current, $total)
            );

            $sessionManager->setResults($this->sessionId, $results);
            $sessionManager->setStatus($this->sessionId, 'complete');
        } catch (\Throwable $e) {
            $sessionManager->setError($this->sessionId, $e->getMessage());
            $sessionManager->setStatus($this->sessionId, 'failed');
        }
    }
}
