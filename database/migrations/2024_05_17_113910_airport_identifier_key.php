<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Apologies to anything that magically teleports half the planet
        DB::table('users')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'users.current_airport_id');
        })
        ->update(['current_airport_id' => 'AYMR']);

        DB::table('aircraft')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'aircraft.current_airport_id');
        })
        ->update(['current_airport_id' => 'AYMR']);
        DB::table('aircraft')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'aircraft.hub_id');
        })
        ->update(['hub_id' => 'AYMR']);

        DB::table('contracts')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'contracts.current_airport_id');
        })
        ->update(['current_airport_id' => 'AYMR']);
        DB::table('contracts')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'contracts.dep_airport_id');
        })
        ->update(['dep_airport_id' => 'AYMR']);
        DB::table('contracts')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'contracts.arr_airport_id');
        })
        ->update(['arr_airport_id' => 'AYMR']);

        DB::table('pireps')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'pireps.departure_airport_id');
        })
        ->update(['departure_airport_id' => 'AYMR']);
        DB::table('pireps')
        ->whereNotExists(function (Builder $q) {
            $q->select(DB::raw(1))
                ->from('airports')
                ->whereColumn('airports.identifier', 'pireps.destination_airport_id');
        })
        ->update(['destination_airport_id' => 'AYMR']);

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('aircraft', function (Blueprint $table) {
            $table->foreign('current_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
            $table->foreign('hub_id')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreign('dep_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
            $table->foreign('arr_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
            $table->foreign('current_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('pireps', function (Blueprint $table) {
            $table->foreign('departure_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
            $table->foreign('destination_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('tours', function (Blueprint $table) {
            $table->foreign('start_airport_id')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('tour_checkpoints', function (Blueprint $table) {
            $table->foreign('checkpoint')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('tour_checkpoint_users', function (Blueprint $table) {
            $table->foreign('checkpoint')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

        Schema::table('tour_users', function (Blueprint $table) {
            $table->foreign('next_checkpoint')->references('identifier')->on('airports')->cascadeOnUpdate();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_airport_id']);
            $table->dropIndex('users_current_airport_id_foreign');
        });

        Schema::table('aircraft', function (Blueprint $table) {
            $table->dropForeign(['current_airport_id']);
            $table->dropForeign(['hub_id']);
            $table->dropIndex('aircraft_current_airport_id_foreign');
            // Skip hub index drop as it's separately indexed already
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['dep_airport_id']);
            $table->dropForeign(['arr_airport_id']);
            $table->dropForeign(['current_airport_id']);
            $table->dropIndex('contracts_dep_airport_id_foreign');
            $table->dropIndex('contracts_arr_airport_id_foreign');
            $table->dropIndex('contracts_current_airport_id_foreign');

        });

        Schema::table('pireps', function (Blueprint $table) {
            $table->dropForeign(['departure_airport_id']);
            $table->dropForeign(['destination_airport_id']);
        });

        Schema::table('tours', function (Blueprint $table) {
            $table->dropForeign(['start_airport_id']);
        });

        Schema::table('tour_checkpoints', function (Blueprint $table) {
            $table->dropForeign(['checkpoint']);
        });

        Schema::table('tour_checkpoint_users', function (Blueprint $table) {
            $table->dropForeign(['checkpoint']);
        });

        Schema::table('tour_users', function (Blueprint $table) {
            $table->dropForeign(['next_checkpoint']);
        });
    }
};
