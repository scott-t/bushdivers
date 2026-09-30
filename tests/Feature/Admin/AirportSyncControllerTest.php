<?php

namespace Tests\Feature\Admin;

use App\Jobs\ExecuteAirportSyncJob;
use App\Jobs\ProcessAirportSyncJob;
use App\Models\Airport;
use App\Models\Enums\SimType;
use App\Models\Role;
use App\Models\User;
use App\Services\AirportSync\AirportSyncService;
use App\Services\AirportSync\AirportSyncSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AirportSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create(['role' => 'airport_manager']);
        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);
    }

    public function test_airport_manager_can_view_airport_sync_page(): void
    {
        $response = $this->actingAs($this->user)->get('/admin/airports/sync');

        $response->assertStatus(200);
    }

    public function test_upload_creates_session_and_dispatches_processing_job(): void
    {
        Queue::fake();
        Storage::fake();

        $csv = UploadedFile::fake()->createWithContent(
            'airports.csv',
            "ident;name;city;country;laty;lonx\nAYMR;Moro;Moro;Papua New Guinea;-6.36;143.23"
        );

        $response = $this->actingAs($this->user)->post('/admin/airports/sync', [
            'file' => $csv,
            'sim_type' => 'fs20',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['sessionId']);

        Queue::assertPushed(ProcessAirportSyncJob::class);

        $session = app(AirportSyncSessionManager::class)->get($response->json('sessionId'));
        $this->assertSame(AirportSyncSessionManager::QUEUED, $session['status']);
    }

    public function test_resolve_updates_review_decision(): void
    {
        [$sessionId, $opId] = $this->readySessionWithUndecidedRename();

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/resolve", [
            'itemId' => $opId,
            'decision' => 'apply',
        ]);

        $response->assertOk();
        $response->assertJsonPath('changeset.operations.0.decision', 'apply');
        $response->assertJsonPath('changeset.summary.unresolved', 0);
    }

    public function test_resolve_rejects_decision_not_valid_for_item_type(): void
    {
        Airport::factory()->create(['identifier' => 'AAAA', 'lat' => -6, 'lon' => 145, 'sim_type' => ['fs20']]);
        $sessionId = $this->readySession([$this->row('AAAA', -6, 145)]);
        $opId = app(AirportSyncSessionManager::class)->get($sessionId)['changeset']['operations'][0]['id'];

        $response = $this->actingAs($this->user)->postJson("/admin/airports/sync/{$sessionId}/resolve", [
            'itemId' => $opId,
            'decision' => 'new',
        ]);

        $response->assertStatus(422);
    }

    public function test_override_resolves_identifier_conflict(): void
    {
        Airport::factory()->create(['identifier' => 'AAAA', 'lat' => -6, 'lon' => 145, 'sim_type' => ['fs20']]);
        $holder = Airport::factory()->create(['identifier' => 'BBBB', 'lat' => -7, 'lon' => 146, 'sim_type' => ['fs20']]);
        $sessionId = $this->readySession([$this->row('BBBB', -6, 145)]);

        $this->assertSame(1, app(AirportSyncSessionManager::class)->get($sessionId)['changeset']['summary']['conflicts']);

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/override", [
            'airport_id' => $holder->id,
            'identifier' => 'bbbb-old',
        ]);

        $response->assertOk();
        $response->assertJsonPath("changeset.identifier_overrides.{$holder->id}", 'BBBB-OLD');
        $response->assertJsonPath('changeset.summary.conflicts', 0);
    }

    public function test_execute_validates_all_review_items_resolved(): void
    {
        Queue::fake();

        [$sessionId] = $this->readySessionWithUndecidedRename();

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/execute", [
            'include_deactivations' => false,
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_execute_dispatches_job_once_when_reviews_are_resolved(): void
    {
        Queue::fake();

        [$sessionId, $opId] = $this->readySessionWithUndecidedRename();

        $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/resolve", [
            'itemId' => $opId,
            'decision' => 'ignore',
        ])->assertOk();

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/execute", [
            'include_deactivations' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['queued' => true]);

        $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/execute", [
            'include_deactivations' => true,
        ])->assertStatus(409);

        Queue::assertPushed(ExecuteAirportSyncJob::class, 1);
        $this->assertSame(AirportSyncSessionManager::EXECUTING, app(AirportSyncSessionManager::class)->get($sessionId)['status']);
    }

    /** An airport ~1nm from the incoming row: a low-confidence rename with no decision */
    private function readySessionWithUndecidedRename(): array
    {
        Airport::factory()->create(['identifier' => 'AAAA', 'lat' => -6, 'lon' => 145, 'sim_type' => ['fs20']]);
        $sessionId = $this->readySession([$this->row('ZZZZ', -6.0167, 145)]);
        $opId = app(AirportSyncSessionManager::class)->get($sessionId)['changeset']['operations'][0]['id'];

        return [$sessionId, $opId];
    }

    private function readySession(array $rows): string
    {
        $sessionManager = app(AirportSyncSessionManager::class);
        $sessionId = $sessionManager->create('fs20', 'temp/airport-sync/test.csv');

        $sessionManager->setChangeset($sessionId, app(AirportSyncService::class)->analyse(new Collection($rows), SimType::MSFS2020));
        $sessionManager->setStatus($sessionId, AirportSyncSessionManager::READY);

        return $sessionId;
    }

    private function row(string $identifier, float $lat, float $lon): array
    {
        return ['identifier' => $identifier, 'name' => $identifier, 'lat' => $lat, 'lon' => $lon, 'size' => 3];
    }
}
