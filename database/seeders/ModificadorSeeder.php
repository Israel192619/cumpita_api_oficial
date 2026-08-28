<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModificadorSeeder extends Seeder
{
    public function run(): void
    {
        $modificadores = [
            ['id' => 1, 'nombre' => 'Guarniciones', 'estacion_id' => 1, 'tipo' => 'multiple', 'requerido' => false, 'activo' => true, 'created_at' => '2026-08-26 23:38:40', 'updated_at' => '2026-08-26 23:38:40'],
            ['id' => 2, 'nombre' => 'Terminos de cocion', 'estacion_id' => 2, 'tipo' => 'unico', 'requerido' => false, 'activo' => true, 'created_at' => '2026-08-26 23:39:35', 'updated_at' => '2026-08-26 23:39:35'],
            ['id' => 3, 'nombre' => 'Poco o sin', 'estacion_id' => 2, 'tipo' => 'unico', 'requerido' => false, 'activo' => true, 'created_at' => '2026-08-26 23:40:52', 'updated_at' => '2026-08-26 23:40:52'],
        ];
        DB::table('modificadores')->upsert($modificadores, ['id']);

        $opciones = [
            [1, 1, 'Papas fritas', true], [2, 1, 'Papa hervida', true],
            [3, 1, 'Yuca', true], [4, 1, 'Ensalada', true],
            [5, 1, 'Mote', true], [6, 1, 'Arroz batido', true],
            [7, 1, 'Arroz graneado', false], [8, 2, 'Bien cocido', true],
            [9, 2, 'Termino medio', true], [10, 3, 'Sin sal', true],
            [11, 3, 'Poca sal', true], [12, 3, 'Sin limon', true],
        ];
        $fechas = [1 => '2026-08-26 23:38:40', 2 => '2026-08-26 23:39:35', 3 => '2026-08-26 23:40:52'];
        $registros = array_map(fn (array $opcion) => [
            'id' => $opcion[0],
            'modificador_id' => $opcion[1],
            'nombre' => $opcion[2],
            'precio_extra' => 0,
            'activo' => $opcion[3],
            'created_at' => $fechas[$opcion[1]],
            'updated_at' => $fechas[$opcion[1]],
        ], $opciones);

        DB::table('modificador_opciones')->upsert($registros, ['id']);
    }
}
