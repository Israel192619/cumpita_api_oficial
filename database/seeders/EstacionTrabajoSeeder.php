<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EstacionTrabajoSeeder extends Seeder
{
    public function run(): void
    {
        $fecha = '2026-08-26 23:07:08';
        DB::table('estaciones_trabajo')->upsert([
            ['id' => 1, 'nombre' => 'Cocina', 'codigo' => 'COCINA', 'descripcion' => 'Estación principal de preparación de alimentos.', 'activa' => true, 'orden' => 1, 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 2, 'nombre' => 'Parrilla', 'codigo' => 'PARRILLA', 'descripcion' => 'Estación de parrilla y carnes.', 'activa' => true, 'orden' => 2, 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 3, 'nombre' => 'Bebidas', 'codigo' => 'BEBIDAS', 'descripcion' => 'Estación de preparación de bebidas y cocteles.', 'activa' => true, 'orden' => 3, 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 4, 'nombre' => 'Meseros', 'codigo' => 'MESEROS', 'descripcion' => 'Estación para atención de meseros y pedidos de servicio.', 'activa' => true, 'orden' => 4, 'created_at' => $fecha, 'updated_at' => $fecha],
        ], ['id']);
    }
}
