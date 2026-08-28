<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PuestoEstacionSeeder extends Seeder
{
    public function run(): void
    {
        $fecha = '2026-08-26 23:07:08';
        DB::table('puestos_estacion')->upsert([
            ['id' => 1, 'estacion_id' => 1, 'nombre' => 'Puesto 1', 'user_id' => null, 'orden_id' => null, 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 2, 'estacion_id' => 1, 'nombre' => 'Puesto 2', 'user_id' => null, 'orden_id' => null, 'created_at' => $fecha, 'updated_at' => $fecha],
        ], ['id']);
    }
}
