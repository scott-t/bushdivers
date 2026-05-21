<?php

namespace App\Services\AirportSync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AirportSyncSessionManager
{
    private const TTL_HOURS = 2;

    public function create(string $simType, string $filePath): string
    {
        $basename = pathinfo($filePath, PATHINFO_FILENAME);
        $sessionId = Str::isUuid($basename) ? $basename : (string) Str::uuid();

        $payload = [
            'session_id' => $sessionId,
            'status' => 'queued',
            'sim_type' => $simType,
            'file_path' => $filePath,
            'progress' => [
                'current' => 0,
                'total' => 0,
            ],
            'results' => null,
            'execution_summary' => null,
            'error' => null,
        ];

        Cache::put($this->cacheKey($sessionId), $payload, now()->addHours(self::TTL_HOURS));

        return $sessionId;
    }

    public function get(string $sessionId): ?array
    {
        return Cache::get($this->cacheKey($sessionId));
    }

    public function setStatus(string $sessionId, string $status): void
    {
        $this->update($sessionId, function (array $session) use ($status) {
            $session['status'] = $status;

            return $session;
        });
    }

    public function setProgress(string $sessionId, int $current, int $total): void
    {
        $this->update($sessionId, function (array $session) use ($current, $total) {
            $session['progress'] = [
                'current' => $current,
                'total' => $total,
            ];

            return $session;
        });
    }

    public function setResults(string $sessionId, array $results): void
    {
        $this->update($sessionId, function (array $session) use ($results) {
            $session['results'] = $results;

            return $session;
        });
    }

    public function setError(string $sessionId, string $error): void
    {
        $this->update($sessionId, function (array $session) use ($error) {
            $session['error'] = $error;

            return $session;
        });
    }

    public function updateReviewDecision(string $sessionId, string $itemId, string $decision): void
    {
        $this->update($sessionId, function (array $session) use ($itemId, $decision) {
            if (! isset($session['results']['review_items'])) {
                return $session;
            }

            $session['results']['review_items'] = collect($session['results']['review_items'])
                ->map(function (array $item) use ($itemId, $decision) {
                    if (($item['id'] ?? null) === $itemId) {
                        $item['admin_decision'] = $decision;
                    }

                    return $item;
                })
                ->values()
                ->all();

            return $session;
        });
    }

    public function extendTtl(string $sessionId): void
    {
        $session = $this->get($sessionId);

        if (! $session) {
            return;
        }

        Cache::put($this->cacheKey($sessionId), $session, now()->addHours(self::TTL_HOURS));
    }

    public function destroy(string $sessionId): void
    {
        Cache::forget($this->cacheKey($sessionId));
    }

    public function setExecutionSummary(string $sessionId, array $summary): void
    {
        $this->update($sessionId, function (array $session) use ($summary) {
            $session['execution_summary'] = $summary;

            return $session;
        });
    }

    private function update(string $sessionId, callable $mutator): void
    {
        $session = $this->get($sessionId);

        if (! $session) {
            return;
        }

        $updated = $mutator($session);

        Cache::put($this->cacheKey($sessionId), $updated, now()->addHours(self::TTL_HOURS));
    }

    private function cacheKey(string $sessionId): string
    {
        return "airport_sync:{$sessionId}";
    }
}
