<?php

namespace App\Http\Controllers\Admin\Airports;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteAirportSyncJob;
use App\Jobs\ProcessAirportSyncJob;
use App\Models\Enums\SimType;
use App\Services\AirportSync\AirportSyncSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AirportSyncController extends Controller
{
    public function __construct(private readonly AirportSyncSessionManager $sessionManager)
    {
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
            'decision' => ['required', Rule::in(['rename', 'new', 'ignore', 'promote'])],
        ]);

        $this->sessionManager->updateReviewDecision($sessionId, $validated['itemId'], $validated['decision']);

        $session = $this->sessionManager->get($sessionId);

        if (! $session) {
            return response()->json(['status' => 'expired'], 404);
        }

        return response()->json($session);
    }

    public function execute(string $sessionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'include_deactivations' => ['required', 'boolean'],
        ]);

        $session = $this->sessionManager->get($sessionId);

        if (! $session) {
            return response()->json(['status' => 'expired'], 404);
        }

        $unresolved = collect($session['results']['review_items'] ?? [])->contains(
            fn (array $item) => empty($item['admin_decision'])
        );

        if ($unresolved) {
            return response()->json(['message' => 'All review items must be resolved before execution.'], 422);
        }

        ExecuteAirportSyncJob::dispatch($sessionId, (bool) $validated['include_deactivations']);

        return response()->json(['queued' => true]);
    }
}
