<?php

namespace Database\Seeders;

use App\Models\EstacionTrabajo;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EstacionTrabajoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $estaciones = [
            [
                'nombre' => 'Cocina',
                'codigo' => 'COCINA',
                'descripcion' => 'Estación principal de preparación de alimentos.',
                'activa' => true,
                'orden' => 1,
            ],
            [
                'nombre' => 'Parrilla',
                'codigo' => 'PARRILLA',
                'descripcion' => 'Estación de parrilla y carnes.',
                'activa' => true,
                'orden' => 2,
            ],
            [
                'nombre' => 'Bebidas',
                'codigo' => 'BEBIDAS',
                'descripcion' => 'Estación de preparación de bebidas y cocteles.',
                'activa' => true,
                'orden' => 3,
            ],
            [
                'nombre' => 'Meseros',
                'codigo' => 'MESEROS',
                'descripcion' => 'Estación para atención de meseros y pedidos de servicio.',
                'activa' => true,
                'orden' => 4,
            ],
        ];

        foreach ($estaciones as $estacion) {
            EstacionTrabajo::updateOrCreate(
                ['codigo' => $estacion['codigo']],
                $estacion
            );
        }
    }
}
