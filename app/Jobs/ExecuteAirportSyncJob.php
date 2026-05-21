<?php

namespace App\Jobs;

use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncExecutor;
use App\Services\AirportSync\AirportSyncSessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExecuteAirportSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $sessionId,
        private readonly bool $includeDeactivations
    ) {
    }

    public function handle(
        AirportSyncSessionManager $sessionManager,
        AirportSyncExecutor $executor
    ): void {
        $session = $sessionManager->get($this->sessionId);

        if (! $session || empty($session['results'])) {
            return;
        }

        try {
            $summary = $executor->execute(
                $session['results'],
                SimType::from($session['sim_type']),
                $this->includeDeactivations
            );

            $sessionManager->setExecutionSummary($this->sessionId, $summary);
            $sessionManager->setStatus($this->sessionId, 'executed');

            if (! empty($session['file_path'])) {
                Storage::delete($session['file_path']);
            }
        } catch (\Throwable $e) {
            $sessionManager->setError($this->sessionId, $e->getMessage());
            $sessionManager->setStatus($this->sessionId, 'failed');
        }
    }
}
