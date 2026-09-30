<?php

namespace App\Services\AirportSync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache-backed state for an admin sync session.
 * Status flow: queued -> processing -> ready -> executing -> executed (or failed at any step).
 */
class AirportSyncSessionManager
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const EXECUTING = 'executing';
    public const EXECUTED = 'executed';
    public const FAILED = 'failed';

    private const TTL_HOURS = 2;

    public function create(string $simType, string $filePath): string
    {
        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $sessionId = Str::isUuid($basename) ? $basename : (string) Str::uuid();

        Cache::put($this->cacheKey($sessionId), [
            'session_id' => $sessionId,
            'status' => self::QUEUED,
            'sim_type' => $simType,
            'file_path' => $filePath,
            'progress' => ['current' => 0, 'total' => 0],
            'changeset' => null,
            'execution_summary' => null,
            'error' => null,
        ], now()->addHours(self::TTL_HOURS));

        return $sessionId;
    }

    public function get(string $sessionId): ?array
    {
        return Cache::get($this->cacheKey($sessionId));
    }

    public function setStatus(string $sessionId, string $status): void
    {
        $this->mutate($sessionId, fn (array $session) => ['status' => $status] + $session);
    }

    public function setProgress(string $sessionId, int $current, int $total): void
    {
        $this->mutate($sessionId, fn (array $session) => ['progress' => compact('current', 'total')] + $session);
    }

    public function setChangeset(string $sessionId, array $changeset): void
    {
        $this->mutate($sessionId, fn (array $session) => ['changeset' => $changeset] + $session);
    }

    public function fail(string $sessionId, string $error, ?array $conflicts = null): void
    {
        $this->mutate($sessionId, function (array $session) use ($error, $conflicts) {
            $session['status'] = self::FAILED;
            $session['error'] = $error;

            if ($conflicts !== null && $session['changeset']) {
                $session['changeset']['conflicts'] = $conflicts;
            }

            return $session;
        });
    }

    public function setExecutionSummary(string $sessionId, array $summary): void
    {
        $this->mutate($sessionId, fn (array $session) => ['execution_summary' => $summary] + $session);
    }

    /**
     * Atomically move from one of $from to $to. Returns false if the session was in any other state.
     */
    public function transition(string $sessionId, array $from, string $to): bool
    {
        $moved = false;

        $this->mutate($sessionId, function (array $session) use ($from, $to, &$moved) {
            if (! in_array($session['status'], $from, true)) {
                return $session;
            }

            $moved = true;
            $session['status'] = $to;

            return $session;
        });

        return $moved;
    }

    /**
     * Locked read-modify-write, so concurrent review decisions can't overwrite each other.
     */
    public function mutate(string $sessionId, callable $mutator): ?array
    {
        return Cache::lock($this->cacheKey($sessionId) . ':lock', 10)->block(5, function () use ($sessionId, $mutator) {
            $session = $this->get($sessionId);

            if (! $session) {
                return null;
            }

            $updated = $mutator($session);

            Cache::put($this->cacheKey($sessionId), $updated, now()->addHours(self::TTL_HOURS));

            return $updated;
        });
    }

    public function extendTtl(string $sessionId): void
    {
        $this->mutate($sessionId, fn (array $session) => $session);
    }

    public function destroy(string $sessionId): void
    {
        Cache::forget($this->cacheKey($sessionId));
    }

    private function cacheKey(string $sessionId): string
    {
        return "airport_sync:{$sessionId}";
    }
}
