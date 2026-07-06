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
            ['nombre' => 'Coca-Cola', 'precio' => 1.50, 'categoria_id' => 2],
            ['nombre' => 'Pepsi', 'precio' => 1.40, 'categoria_id' => 2],
            ['nombre' => 'Hamburguesa', 'precio' => 5.00, 'categoria_id' => 3],
            ['nombre' => 'Pizza', 'precio' => 8.00, 'categoria_id' => 3],
            ['nombre' => 'Helado', 'precio' => 3.00, 'categoria_id' => 4],
        ];

        foreach ($productos as $producto) {
            \App\Models\Producto::create($producto);
        }
    }
}
