<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DespachoSeeder extends Seeder
{
    public function run(): void
    {
        $rol = Role::firstOrCreate(
            ['nombre' => 'Despacho'],
            ['descripcion' => 'Acceso de la tablet al módulo Servicio']
        );

        User::updateOrCreate(
            ['email' => env('DESPACHO_EMAIL', 'despacho@tonito.local')],
            [
                'name' => 'Despacho',
                'username' => 'despacho',
                'password' => Hash::make(env('DESPACHO_PASSWORD', 'Despacho2026***')),
                'role_id' => $rol->id,
                'pin' => null,
            ]
        );
    }
}
