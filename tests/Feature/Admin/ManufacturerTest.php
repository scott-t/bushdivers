<?php

namespace Tests\Feature\Admin;

use App\Models\Manufacturer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user with fleet_admin role for testing
        $this->user = User::factory()->create()->refresh();
        $this->user->roles()->attach(Role::where('role', 'fleet_admin')->first());
    }

    public function test_fleet_admin_can_view_manufacturers_list()
    {
        // Create some manufacturers
        Manufacturer::factory()->create([
            'name' => 'Cessna',
            'logo_url' => 'https://example.com/cessna.png',
        ]);

        Manufacturer::factory()->create([
            'name' => 'Piper',
            'logo_url' => 'https://example.com/piper.png',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.manufacturers'));

        $response->assertStatus(200);
    }

    public function test_fleet_admin_can_create_manufacturer()
    {
        $manufacturerData = [
            'name' => 'Beechcraft',
            'logo_url' => 'https://example.com/beechcraft.png',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('admin.manufacturers.store'), $manufacturerData);

        $response->assertRedirect(route('admin.manufacturers'));

        $this->assertDatabaseHas('manufacturers', [
            'name' => 'Beechcraft',
            'logo_url' => 'https://example.com/beechcraft.png',
        ]);
    }

    public function test_fleet_admin_can_update_manufacturer()
    {
        $manufacturer = Manufacturer::factory()->create([
            'name' => 'Old Name',
            'logo_url' => 'https://example.com/old.png',
        ]);

        $updatedData = [
            'name' => 'New Name',
            'logo_url' => 'https://example.com/new.png',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('admin.manufacturers.update', $manufacturer->id), $updatedData);

        $response->assertRedirect(route('admin.manufacturers'));

        $this->assertDatabaseHas('manufacturers', [
            'id' => $manufacturer->id,
            'name' => 'New Name',
            'logo_url' => 'https://example.com/new.png',
        ]);
    }

    public function test_fleet_admin_can_delete_manufacturer()
    {
        $manufacturer = Manufacturer::factory()->create([
            'name' => 'Test Manufacturer',
            'logo_url' => 'https://example.com/test.png',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.manufacturers.delete', $manufacturer->id));

        $response->assertRedirect(route('admin.manufacturers'));

        $this->assertDatabaseMissing('manufacturers', [
            'id' => $manufacturer->id,
        ]);
    }

    public function test_manufacturer_name_is_required()
    {
        $manufacturerData = [
            'name' => '',
            'logo_url' => 'https://example.com/test.png',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('admin.manufacturers.store'), $manufacturerData);

        $response->assertSessionHasErrors('name');
    }
}
