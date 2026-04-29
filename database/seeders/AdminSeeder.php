<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@gmail.com',
            'password' => bcrypt('Admin2026***'),
            'role_id' => 1, 
        ]);
        
        $user->PerfilUsuarios()->create([
            'direccion' => 'Av. Simon Lopez',
            'numero_celular' => '70404505',
            'avatar' => null,
        ]);
    }
}
