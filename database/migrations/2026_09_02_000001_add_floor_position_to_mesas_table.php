<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->decimal('posicion_x', 5, 2)->nullable()->after('estado');
            $table->decimal('posicion_y', 5, 2)->nullable()->after('posicion_x');
        });

        $posiciones = [
            '1' => [58, 9], '2' => [58, 22], '3' => [58, 35], '4' => [58, 48], '5' => [58, 61],
            '6' => [78, 9], '7' => [78, 22], '8' => [78, 35], '9' => [78, 48], '10' => [78, 61], '11' => [78, 74],
            '12' => [34, 87], '13' => [20, 87], '14' => [7, 87], '15' => [7, 70], '16' => [7, 54],
            '17' => [20, 54], '18' => [34, 54], '19' => [25, 70],
        ];

        foreach ($posiciones as $numero => [$x, $y]) {
            DB::table('mesas')->where('numero', $numero)->update([
                'posicion_x' => $x,
                'posicion_y' => $y,
            ]);
        }

        foreach (['7', '17', '18', '19'] as $numero) {
            if (! DB::table('mesas')->where('numero', $numero)->exists()) {
                [$x, $y] = $posiciones[$numero];
                DB::table('mesas')->insert([
                    'numero' => $numero,
                    'capacidad' => 4,
                    'estado' => 'libre',
                    'posicion_x' => $x,
                    'posicion_y' => $y,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn(['posicion_x', 'posicion_y']);
        });
    }
};
