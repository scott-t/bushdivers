<?php

namespace Tests\Unit\Rentals;

use App\Models\Aircraft;
use App\Models\Enums\TransactionTypes;
use App\Models\Fleet;
use App\Models\Rental;
use App\Models\User;
use App\Services\Rentals\EndRental;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndRentalTest extends TestCase
{
    use RefreshDatabase;

    protected Model $fleet;
    protected Model $aircraftHome;
    protected Model $aircraftAway;
    protected Model $user;
    protected EndRental $endRental;

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->fleet = Fleet::factory()->create([
            'rental_cost' => 200.00
        ]);

        $this->user = User::factory()->create();

        $this->aircraftHome = Rental::factory()->create([
            'fleet_id' => $this->fleet->id,
            'current_airport_id' => 'AYMR',
            'rental_airport_id' => 'AYMR',
            'user_id' => $this->user->id,
            'registration' => 'N12345'
        ]);

        $this->aircraftAway = Rental::factory()->create([
            'fleet_id' => $this->fleet->id,
            'current_airport_id' => 'AYMH',
            'rental_airport_id' => 'AYMR',
            'user_id' => $this->user->id,
            'registration' => 'N1234A'
        ]);

        $this->endRental = $this->app->make(EndRental::class);
    }

    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function test_deposit_is_returned_when_at_home_location()
    {
        $this->endRental->execute($this->aircraftHome->id, $this->user->id);
        $this->assertDatabaseHas('user_accounts',[
            'total' => 400,
            'type' => TransactionTypes::Rental,
            'user_id' => $this->user->id
        ]);
    }

    public function test_deposit_not_returned_when_away_from_home()
    {
        $this->endRental->execute($this->aircraftAway->id, $this->user->id);
        $this->assertDatabaseMissing('user_accounts',[
            'total' => 2000,
            'type' => TransactionTypes::Rental,
            'user_id' => $this->user->id
        ]);
    }

    public function test_rental_made_inactive()
    {
        $this->endRental->execute($this->aircraftHome->id, $this->user->id);
        $this->aircraftHome->refresh();
        $this->assertEquals(0, $this->aircraftHome->is_active);
    }
}
