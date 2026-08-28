<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        $fechaBase = '2026-08-26 23:07:08';
        $productos = [
            ['id' => 1, 'categoria_id' => 4, 'estacion_id' => 3, 'nombre' => 'Coca-Cola', 'precio' => 15, 'maneja_stock' => true, 'stock' => 50, 'stock_minimo' => 10, 'created_at' => $fechaBase, 'updated_at' => '2026-08-26 23:45:27'],
            ['id' => 3, 'categoria_id' => 3, 'estacion_id' => 4, 'nombre' => 'Moconchinchi Pequeño', 'precio' => 10, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => '2026-08-26 23:46:17'],
            ['id' => 4, 'categoria_id' => 3, 'estacion_id' => 4, 'nombre' => 'Moconchinchi Grande', 'precio' => 10, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => '2026-08-26 23:46:27'],
            ['id' => 5, 'categoria_id' => 5, 'estacion_id' => 2, 'nombre' => 'Pescado mediano', 'precio' => 45, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 6, 'categoria_id' => 5, 'estacion_id' => 2, 'nombre' => 'Pescado grande', 'precio' => 50, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 7, 'categoria_id' => 5, 'estacion_id' => 2, 'nombre' => 'Pescado desespinado', 'precio' => 60, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 8, 'categoria_id' => 6, 'estacion_id' => 1, 'nombre' => 'Pollo de una presa', 'precio' => 17, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 9, 'categoria_id' => 6, 'estacion_id' => 1, 'nombre' => 'Pollo doble presa', 'precio' => 28, 'maneja_stock' => false, 'stock' => null, 'stock_minimo' => null, 'created_at' => $fechaBase, 'updated_at' => $fechaBase],
            ['id' => 11, 'categoria_id' => 10, 'estacion_id' => 4, 'nombre' => 'Jugo grande', 'precio' => 18, 'maneja_stock' => true, 'stock' => 40, 'stock_minimo' => 5, 'created_at' => '2026-08-26 23:51:37', 'updated_at' => '2026-08-26 23:51:37'],
        ];

        $productos = array_map(fn (array $producto) => [
            ...$producto,
            'descripcion' => null,
            'sku' => null,
            'imagen' => null,
            'orden' => 0,
            'activo' => true,
            'destacado' => false,
        ], $productos);

        DB::table('productos')->upsert($productos, ['id']);

        $asociaciones = [
            [1, 5, 8, 0], [2, 5, 9, 0], [3, 5, 10, 0], [4, 5, 11, 0], [5, 5, 12, 0],
            [6, 6, 10, 0], [7, 6, 11, 0], [8, 6, 12, 0], [9, 6, 8, 0], [10, 6, 9, 0],
            [11, 7, 10, 0], [12, 7, 11, 0], [13, 7, 12, 0], [14, 7, 8, 0], [15, 7, 9, 0],
            [16, 8, 1, 1], [17, 8, 2, 0], [18, 8, 3, 0], [19, 8, 4, 1], [20, 8, 5, 0], [21, 8, 6, 1], [22, 8, 7, 0],
            [23, 9, 1, 1], [24, 9, 2, 0], [25, 9, 3, 0], [26, 9, 4, 1], [27, 9, 5, 0], [28, 9, 6, 1], [29, 9, 7, 0],
            [30, 7, 1, 0], [31, 7, 2, 1], [32, 7, 3, 1], [33, 7, 4, 1], [34, 7, 5, 1], [35, 7, 6, 0], [36, 7, 7, 0],
            [37, 6, 1, 0], [38, 6, 2, 1], [39, 6, 3, 1], [40, 6, 4, 1], [41, 6, 5, 1], [42, 6, 6, 0], [43, 6, 7, 0],
            [44, 5, 1, 0], [45, 5, 2, 1], [46, 5, 3, 1], [47, 5, 4, 1], [48, 5, 5, 1], [49, 5, 6, 0], [50, 5, 7, 0],
        ];

        $registros = array_map(fn (array $opcion) => [
            'id' => $opcion[0],
            'producto_id' => $opcion[1],
            'modificador_opcion_id' => $opcion[2],
            'predeterminado' => (bool) $opcion[3],
            'created_at' => null,
            'updated_at' => null,
        ], $asociaciones);

        DB::table('producto_opciones')->upsert($registros, ['id']);
    }
}
