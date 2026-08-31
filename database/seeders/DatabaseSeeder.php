<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(EstacionTrabajoSeeder::class);
        $this->call(UsuarioSeeder::class);
        $this->call(CategoriaSeeder::class);
        $this->call(MesaSeeder::class);
        $this->call(ModificadorSeeder::class);
        $this->call(ProductoSeeder::class);
    }
}
