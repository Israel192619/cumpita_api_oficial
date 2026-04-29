<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['nombre' => 'Admin', 'descripcion' => 'Administrador del sistema'],
            ['nombre' => 'Cocinero', 'descripcion' => 'Encargado de preparar los alimentos'],
            ['nombre' => 'Mesero', 'descripcion' => 'Encargado de atender a los clientes'],
            ['nombre' => 'Cajero', 'descripcion' => 'Encargado de gestionar las transacciones financieras'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}
