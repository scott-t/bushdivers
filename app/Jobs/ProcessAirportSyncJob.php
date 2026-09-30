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
use Illuminate\Support\Facades\Storage;

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
        if (! $sessionManager->transition($this->sessionId, [AirportSyncSessionManager::QUEUED], AirportSyncSessionManager::PROCESSING)) {
            return;
        }

        $session = $sessionManager->get($this->sessionId);

        try {
            // file_path is relative to the storage disk it was uploaded to
            $path = Storage::path($session['file_path']);

            $changeset = $airportSyncService->analyse(
                $parser->parse($path),
                SimType::from($session['sim_type']),
                fn (int $current, int $total) => $sessionManager->setProgress($this->sessionId, $current, $total)
            );

            $sessionManager->setChangeset($this->sessionId, $changeset);
            $sessionManager->setStatus($this->sessionId, AirportSyncSessionManager::READY);
        } catch (\Throwable $e) {
            report($e);
            $sessionManager->fail($this->sessionId, $e->getMessage());
        }
    }
}
