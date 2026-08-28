<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MesaSeeder extends Seeder
{
    public function run(): void
    {
        $mesas = [
            [1, '1', 2, '2026-08-26 23:41:44'],
            [2, '2', 4, '2026-08-26 23:41:51'],
            [3, '3', 2, '2026-08-26 23:41:56'],
            [4, '4', 4, '2026-08-26 23:42:06'],
            [5, '5', 8, '2026-08-26 23:42:11'],
            [6, '6', 8, '2026-08-26 23:42:15'],
            [7, '8', 8, '2026-08-26 23:42:24'],
            [8, '9', 8, '2026-08-26 23:42:28'],
            [9, '10', 4, '2026-08-26 23:42:36'],
            [10, '11', 4, '2026-08-26 23:42:46'],
            [11, '12', 4, '2026-08-26 23:42:51'],
            [12, '13', 4, '2026-08-26 23:42:56'],
            [13, '14', 4, '2026-08-26 23:43:00'],
            [14, '15', 4, '2026-08-26 23:43:03'],
            [15, '16', 3, '2026-08-26 23:43:09'],
        ];

        $registros = array_map(fn (array $mesa) => [
            'id' => $mesa[0],
            'numero' => $mesa[1],
            'capacidad' => $mesa[2],
            'estado' => 'libre',
            'created_at' => $mesa[3],
            'updated_at' => $mesa[3],
        ], $mesas);

        DB::table('mesas')->upsert(
            $registros,
            ['id'],
            ['numero', 'capacidad', 'estado', 'created_at', 'updated_at']
        );
    }
}
