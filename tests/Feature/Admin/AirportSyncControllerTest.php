<?php

namespace Tests\Feature\Admin;

use App\Jobs\ExecuteAirportSyncJob;
use App\Jobs\ProcessAirportSyncJob;
use App\Models\Role;
use App\Models\User;
use App\Services\AirportSync\AirportSyncSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->assertNotNull($session);
    }

    public function test_resolve_updates_review_decision(): void
    {
        $sessionManager = app(AirportSyncSessionManager::class);
        $sessionId = $sessionManager->create('fs20', 'temp/airport-sync/test.csv');

        $sessionManager->setResults($sessionId, [
            'review_items' => [[
                'id' => 'abc-123',
                'admin_decision' => null,
            ]],
        ]);

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/resolve", [
            'itemId' => 'abc-123',
            'decision' => 'rename',
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.review_items.0.admin_decision', 'rename');
    }

    public function test_execute_validates_all_review_items_resolved(): void
    {
        Queue::fake();

        $sessionManager = app(AirportSyncSessionManager::class);
        $sessionId = $sessionManager->create('fs20', 'temp/airport-sync/test.csv');

        $sessionManager->setResults($sessionId, [
            'review_items' => [[
                'id' => 'abc-123',
                'admin_decision' => null,
            ]],
        ]);

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/execute", [
            'include_deactivations' => false,
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_execute_dispatches_job_when_reviews_are_resolved(): void
    {
        Queue::fake();

        $sessionManager = app(AirportSyncSessionManager::class);
        $sessionId = $sessionManager->create('fs20', 'temp/airport-sync/test.csv');

        $sessionManager->setResults($sessionId, [
            'review_items' => [[
                'id' => 'abc-123',
                'admin_decision' => 'new',
            ]],
        ]);

        $response = $this->actingAs($this->user)->post("/admin/airports/sync/{$sessionId}/execute", [
            'include_deactivations' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['queued' => true]);
        Queue::assertPushed(ExecuteAirportSyncJob::class);
    }
}
