<?php

namespace Tests\Feature\Crew;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ProcessJumpseatTest extends TestCase
{
    use RefreshDatabase;

    protected Model $user;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->user = User::factory()->create([
            'current_airport_id' => 'PAMX'
        ]);
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_user_moves_to_new_destination()
    {
        $data = [
            'cost' => 2.00,
            'icao' => 'AYMR'
        ];
        $this->actingAs($this->user)->post('/jumpseat', $data);
        $this->user->refresh();
        $this->assertEquals('AYMR', $this->user->current_airport_id);
    }

    public function test_cost_added_to_user_transactions()
    {
        $data = [
            'cost' => 2.00,
            'icao' => 'AYMH'
        ];
        $this->actingAs($this->user)->post('/jumpseat', $data);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => $this->user->id,
            'total' => -2.00
        ]);
    }

    public function test_no_cost_added_to_user_transactions_if_between_hq()
    {
        $data = [
            'cost' => 2.00,
            'icao' => 'AYMR'
        ];
        $this->actingAs($this->user)->post('/jumpseat', $data);
        $this->assertDatabaseMissing('user_accounts', [
            'user_id' => $this->user->id,
            'total' => -2.00
        ]);
    }
}
