<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $fecha = '2026-08-26 23:07:07';
        DB::table('roles')->upsert([
            ['id' => 1, 'nombre' => 'Admin', 'descripcion' => 'Administrador del sistema', 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 2, 'nombre' => 'Cocinero', 'descripcion' => 'Encargado de preparar los alimentos', 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 3, 'nombre' => 'Mesero', 'descripcion' => 'Encargado de atender a los clientes', 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 4, 'nombre' => 'Cajero', 'descripcion' => 'Encargado de gestionar las transacciones financieras', 'created_at' => $fecha, 'updated_at' => $fecha],
            ['id' => 5, 'nombre' => 'Despacho', 'descripcion' => 'Acceso de la tablet al módulo Servicio', 'created_at' => $fecha, 'updated_at' => $fecha],
        ], ['id']);
    }
}
