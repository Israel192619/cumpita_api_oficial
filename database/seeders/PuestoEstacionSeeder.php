<?php

namespace Database\Seeders;

use App\Models\EstacionTrabajo;
use App\Models\PuestoEstacion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PuestoEstacionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $cocina = EstacionTrabajo::where('codigo', 'COCINA')->first();

        if (!$cocina) {
            return;
        }

        $puestos = [
            ['nombre' => 'Puesto 1'],
            ['nombre' => 'Puesto 2'],
        ];

        foreach ($puestos as $puesto) {
            PuestoEstacion::updateOrCreate(
                ['estacion_id' => $cocina->id, 'nombre' => $puesto['nombre']],
                ['user_id' => null]
            );
        }
    }
}
