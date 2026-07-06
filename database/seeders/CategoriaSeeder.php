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
            ['nombre' => 'Bebidas', 'descripcion' => 'Bebidas refrescantes', 'parent_id' => null],
            ['nombre' => 'Gaseosas', 'descripcion' => 'Gaseoasas refrescantes', 'parent_id' => 1],
            ['nombre' => 'Comidas', 'descripcion' => 'Comidas deliciosas', 'parent_id' => null],
            ['nombre' => 'Postres', 'descripcion' => 'Postres deliciosos', 'parent_id' => null],
        ];

        foreach ($categorias as $categoria) {
            \App\Models\Categoria::create($categoria);
        }
    }
}
