<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->integer('min_cargo_split')->default(1)->after('cargo_type');
        });

        Schema::table('cargo_types', function (Blueprint $table) {
            $table->integer('min_payload')->default(1)->after('text');
            $table->integer('max_payload')->default(100000)->after('min_payload');
        });

        // Update contracts based on cargo_types min split
        $cargoTypes = \App\Models\CargoType::all();
        foreach ($cargoTypes as $cargoType) {
            \App\Models\Contract::where('cargo', $cargoType->text)
                ->update(['min_cargo_split' => $cargoType->min_cargo_split]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('min_cargo_split');
        });

        Schema::table('cargo_types', function (Blueprint $table) {
            $table->dropColumn('min_payload');
            $table->dropColumn('max_payload');
        });
    }
};
