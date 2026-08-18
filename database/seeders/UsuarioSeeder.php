<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usuarios = [
            ['name' => 'JuanCocinero1', 'username' => 'cocineroCocina1', 'email' => 'cocineroCocina1@gmail.com', 'password' => bcrypt('Admin2026***'), 'role_id' => 2, 'estacion_id' => 1],
            ['name' => 'JuanCocinero2', 'username' => 'cocineroCocina2', 'email' => 'cocineroCocina2@gmail.com', 'password' => bcrypt('Admin2026***'), 'role_id' => 2, 'estacion_id' => 1],
            ['name' => 'Toto', 'username' => 'cocineroParrilla', 'email' => 'cocineroParrilla@gmail.com', 'password' => bcrypt('Admin2026***'), 'role_id' => 2, 'estacion_id' => 2],
            ['name' => 'Pedro', 'username' => 'mesero', 'email' => 'mesero@gmail.com', 'password' => bcrypt('Admin2026***'), 'role_id' => 2, 'estacion_id' => 4],
        ];

        foreach ($usuarios as $usuario) {
            \App\Models\User::create($usuario);
        }

        $perfilUsuarios = [
            ['user_id' => 2, 'direccion' => 'Av. Simon Lopez', 'numero_celular' => '70404505', 'avatar' => null],
            ['user_id' => 3, 'direccion' => 'Av. Simon Lopez', 'numero_celular' => '70404505', 'avatar' => null],
            ['user_id' => 4, 'direccion' => 'Av. Simon Lopez', 'numero_celular' => '70404505', 'avatar' => null],
            ['user_id' => 5, 'direccion' => 'Av. Simon Lopez', 'numero_celular' => '70404505', 'avatar' => null],
        ];

        foreach ($perfilUsuarios as $perfilUsuario) {
            \App\Models\PerfilUsuario::create($perfilUsuario);
        }

    }
}
