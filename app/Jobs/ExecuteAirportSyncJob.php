<?php

namespace App\Jobs;

use App\Services\AirportSync\AirportSyncConflictException;
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

    /** Never retry: a partial failure rolls back, and the admin should look at why */
    public int $tries = 1;

    public function __construct(
        private readonly string $sessionId,
        private readonly bool $includeUntags
    ) {
    }

    public function handle(
        AirportSyncSessionManager $sessionManager,
        AirportSyncExecutor $executor
    ): void {
        $session = $sessionManager->get($this->sessionId);

        // The controller moves the session to "executing" before dispatching; anything else is a duplicate
        if (! $session || $session['status'] !== AirportSyncSessionManager::EXECUTING || empty($session['changeset'])) {
            return;
        }

        try {
            $summary = $executor->execute($session['changeset'], $this->includeUntags);

            $sessionManager->setExecutionSummary($this->sessionId, $summary);
            $sessionManager->setStatus($this->sessionId, AirportSyncSessionManager::EXECUTED);

            if (! empty($session['file_path'])) {
                Storage::delete($session['file_path']);
            }
        } catch (AirportSyncConflictException $e) {
            // Nothing was written. Hand it back to the admin with the fresh conflicts.
            $sessionManager->mutate($this->sessionId, function (array $session) use ($e) {
                $session['status'] = AirportSyncSessionManager::READY;
                $session['error'] = $e->getMessage();

                if ($e->conflicts) {
                    $session['changeset']['conflicts'] = $e->conflicts;
                }

                return $session;
            });
        } catch (\Throwable $e) {
            report($e);
            $sessionManager->fail($this->sessionId, $e->getMessage());
        }
    }
}
