<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            ['nombre' => 'Platillos principales', 'descripcion' => 'Platillos deliciosas', 'parent_id' => null],
            ['nombre' => 'Bebidas', 'descripcion' => 'Bebidas refrescantes', 'parent_id' => null],
            ['nombre' => 'Hervidos', 'descripcion' => 'Bebidas refrescantes', 'parent_id' => 2],
            ['nombre' => 'Gaseosas', 'descripcion' => 'Gaseoasas refrescantes', 'parent_id' => 2],
            ['nombre' => 'Pescados', 'descripcion' => 'Pescados a la parrilla', 'parent_id' => 1],
            ['nombre' => 'Pollos', 'descripcion' => 'Pollos a la parrilla', 'parent_id' => 1],
            ['nombre' => 'Postres', 'descripcion' => 'Postres deliciosos', 'parent_id' => null],
        ];

        foreach ($categorias as $categoria) {
            \App\Models\Categoria::create($categoria);
        }
    }
}
