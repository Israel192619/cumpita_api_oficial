<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productos = [
            ['nombre' => 'Coca-Cola', 'precio' => 15, 'categoria_id' => 4],
            ['nombre' => 'Pepsi', 'precio' => 15, 'categoria_id' => 4],
            ['nombre' => 'Moconchinchi Pequeño', 'precio' => 10, 'categoria_id' => 3],
            ['nombre' => 'Moconchinchi Grande', 'precio' => 10, 'categoria_id' => 3],
            ['nombre' => 'Pescado clasico', 'precio' => 45, 'categoria_id' => 5],
            ['nombre' => 'Pescado clasico grande', 'precio' => 50, 'categoria_id' => 5],
            ['nombre' => 'Pescado desespinado', 'precio' => 60, 'categoria_id' => 5],
            ['nombre' => 'Pollo de una presa', 'precio' => 17, 'categoria_id' => 6],
            ['nombre' => 'Pollo doble presa', 'precio' => 28, 'categoria_id' => 6],
            ['nombre' => 'Pizza', 'precio' => 8, 'categoria_id' => 7],
            ['nombre' => 'Helado', 'precio' => 3, 'categoria_id' => 7],
        ];

        foreach ($productos as $producto) {
            \App\Models\Producto::create($producto);
        }
    }
}
