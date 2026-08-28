<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriaSeeder extends Seeder
{
    public function run(): void
    {
        $fechaBase = '2026-08-26 23:07:08';
        $categorias = [
            ['id' => 1, 'nombre' => 'Platillos', 'descripcion' => 'Platillos deliciosas', 'parent_id' => null, 'created_at' => $fechaBase, 'updated_at' => '2026-08-26 23:30:48'],
            ['id' => 2, 'nombre' => 'Bebidas', 'descripcion' => 'Bebidas refrescantes', 'parent_id' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 3, 'nombre' => 'Hervidos', 'descripcion' => 'Bebidas refrescantes', 'parent_id' => 2, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 4, 'nombre' => 'Gaseosas', 'descripcion' => 'Gaseoasas refrescantes', 'parent_id' => 2, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 5, 'nombre' => 'Pescados', 'descripcion' => 'Pescados a la parrilla', 'parent_id' => 1, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 6, 'nombre' => 'Pollos', 'descripcion' => 'Pollos a la parrilla', 'parent_id' => 1, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 8, 'nombre' => 'Fideos Uchu', 'descripcion' => 'Aji de fideo', 'parent_id' => 1, 'created_at' => '2026-08-26 23:31:30', 'updated_at' => '2026-08-26 23:31:30'],
            ['id' => 9, 'nombre' => 'Sopa de mani', 'descripcion' => 'Sopa', 'parent_id' => 1, 'created_at' => '2026-08-26 23:31:57', 'updated_at' => '2026-08-26 23:32:14'],
            ['id' => 10, 'nombre' => 'Jugos', 'descripcion' => 'jugos del valle', 'parent_id' => 2, 'created_at' => '2026-08-26 23:33:38', 'updated_at' => '2026-08-26 23:33:38'],
            ['id' => 11, 'nombre' => 'Aguas', 'descripcion' => null, 'parent_id' => 2, 'created_at' => '2026-08-26 23:34:37', 'updated_at' => '2026-08-26 23:34:37'],
            ['id' => 12, 'nombre' => 'Extras', 'descripcion' => null, 'parent_id' => null, 'created_at' => '2026-08-26 23:35:04', 'updated_at' => '2026-08-26 23:35:04'],
        ];

        DB::table('categorias')->upsert(
            $categorias,
            ['id'],
            ['nombre', 'descripcion', 'parent_id', 'created_at', 'updated_at']
        );
    }
}
