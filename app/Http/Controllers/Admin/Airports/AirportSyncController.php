<?php

namespace App\Http\Controllers\Admin\Airports;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteAirportSyncJob;
use App\Jobs\ProcessAirportSyncJob;
use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\AirportSyncSessionManager;
use App\Services\AirportSync\IdentifierResolver;
use App\Services\AirportSync\SyncOp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AirportSyncController extends Controller
{
    public function __construct(
        private readonly AirportSyncSessionManager $sessionManager,
        private readonly AirportSyncService $syncService,
    ) {
    }

    public function show(): Response
    {
        return Inertia::render('Admin/AirportSync', [
            'sessionId' => null,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'sim_type' => ['required', Rule::enum(SimType::class)],
        ]);

        $provisionalSessionId = (string) Str::uuid();
        $path = $validated['file']->storeAs('temp/airport-sync', "{$provisionalSessionId}.csv");

        $sessionId = $this->sessionManager->create($validated['sim_type'], $path);

        ProcessAirportSyncJob::dispatch($sessionId);

        return response()->json(['sessionId' => $sessionId]);
    }

    public function status(string $sessionId): JsonResponse
    {
        $this->sessionManager->extendTtl($sessionId);

        $session = $this->sessionManager->get($sessionId);

        if (! $session) {
            return response()->json(['status' => 'expired']);
        }

        return response()->json($session);
    }

    public function resolve(string $sessionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'itemId' => ['required', 'string'],
            'decision' => ['required', Rule::in([SyncOp::APPLY, SyncOp::NEW, SyncOp::IGNORE])],
            'candidate_id' => ['nullable', 'integer'],
        ]);

        return $this->updateChangeset($sessionId, function (array $changeset) use ($validated) {
            $index = collect($changeset['operations'])->search(fn (array $op) => $op['id'] === $validated['itemId']);

            if ($index === false) {
                throw ValidationException::withMessages(['itemId' => 'Unknown review item.']);
            }

            $op = $changeset['operations'][$index];

            if (! in_array($validated['decision'], SyncOp::allowedDecisions($op['type']), true)) {
                throw ValidationException::withMessages(['decision' => "'{$validated['decision']}' is not valid for a {$op['type']} item."]);
            }

            if ($op['type'] === SyncOp::AMBIGUOUS && $validated['decision'] === SyncOp::APPLY) {
                if (! collect($op['candidates'])->contains('id', $validated['candidate_id'] ?? null)) {
                    throw ValidationException::withMessages(['candidate_id' => 'Pick one of the listed candidate airports.']);
                }

                $op['candidate_id'] = (int) $validated['candidate_id'];
            }

            $op['decision'] = $validated['decision'];
            $changeset['operations'][$index] = $op;

            return $changeset;
        });
    }

    /**
     * Give an existing airport a new identifier (or clear that override), to settle an identifier conflict.
     */
    public function override(string $sessionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'airport_id' => ['required', 'integer', 'exists:airports,id'],
            'identifier' => ['nullable', 'string', 'max:15'],
        ]);

        $identifier = $validated['identifier'] !== null ? strtoupper(trim($validated['identifier'])) : null;

        if ($identifier !== null && ! preg_match(IdentifierResolver::IDENTIFIER_PATTERN, $identifier)) {
            throw ValidationException::withMessages(['identifier' => 'Use 1-15 letters, digits, _ or -.']);
        }

        return $this->updateChangeset($sessionId, function (array $changeset) use ($validated, $identifier) {
            if ($identifier === null) {
                unset($changeset['identifier_overrides'][$validated['airport_id']]);
            } else {
                $changeset['identifier_overrides'][$validated['airport_id']] = $identifier;
            }

            return $changeset;
        });
    }

    public function execute(string $sessionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_deactivations' => ['required', 'boolean'],
        ]);

        $session = $this->sessionManager->mutate($sessionId, function (array $session) {
            if ($session['status'] === AirportSyncSessionManager::READY && $session['changeset']) {
                $session['changeset'] = $this->syncService->refresh($session['changeset']);
            }

            return $session;
        });

        if (! $session) {
            return response()->json(['status' => 'expired'], 404);
        }

        if ($session['status'] !== AirportSyncSessionManager::READY) {
            return response()->json(['message' => "This sync is {$session['status']} and can't be executed."], 409);
        }

        $summary = $session['changeset']['summary'];

        if ($summary['unresolved'] > 0 || $summary['conflicts'] > 0) {
            return response()->json(array_merge($session, [
                'message' => 'All review items and conflicts must be resolved before execution.',
            ]), 422);
        }

        // Atomic, so a double submit only ever queues one execution
        if (! $this->sessionManager->transition($sessionId, [AirportSyncSessionManager::READY], AirportSyncSessionManager::EXECUTING)) {
            return response()->json(['message' => 'This sync is already being executed.'], 409);
        }

        ExecuteAirportSyncJob::dispatch($sessionId, (bool) $validated['include_deactivations']);

        return response()->json(['queued' => true]);
    }

    private function updateChangeset(string $sessionId, callable $mutator): JsonResponse
    {
        $session = $this->sessionManager->mutate($sessionId, function (array $session) use ($mutator) {
            if ($session['status'] !== AirportSyncSessionManager::READY || ! $session['changeset']) {
                return $session;
            }

            $session['changeset'] = $this->syncService->refresh($mutator($session['changeset']));
            $session['error'] = null;

            return $session;
        });

        if (! $session) {
            return response()->json(['status' => 'expired'], 404);
        }

        if ($session['status'] !== AirportSyncSessionManager::READY) {
            return response()->json(['message' => "This sync is {$session['status']} and can no longer be changed."], 409);
        }

        return response()->json($session);
    }
}
