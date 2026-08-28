<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rolAdministrador = Role::where('nombre', 'Admin')->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'username' => 'admin',
                'password' => Hash::make('Admin2026***'),
                'role_id' => $rolAdministrador->id,
            ]
        );
        
        $user->perfilUsuarios()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'direccion' => 'Av. Simon Lopez',
                'numero_celular' => '70404505',
                'avatar' => null,
            ]
        );
    }
}
