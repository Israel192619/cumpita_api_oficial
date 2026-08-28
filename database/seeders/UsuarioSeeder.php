<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            [
                'id' => 1, 'name' => 'Despacho', 'username' => 'despacho',
                'email' => 'despacho@tonito.local',
                'password' => '$2y$12$4Iceep4xhiDUQDWOTJDE9e6.wLTNjf6F8igdFyTWED6wrEUYD7uBO',
                'pin' => null, 'role_id' => 5, 'estacion_id' => null,
                'created_at' => '2026-08-26 23:07:08', 'updated_at' => '2026-08-26 23:07:08',
            ],
            [
                'id' => 2, 'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@gmail.com',
                'password' => '$2y$12$rJGtMzLMx/O04Y8BHM7shu5Wfc2R2VuhkqnGr.VTKilmjFIvP/hpu',
                'pin' => null, 'role_id' => 1, 'estacion_id' => null,
                'created_at' => '2026-08-26 23:07:08', 'updated_at' => '2026-08-26 23:07:08',
            ],
            [
                'id' => 3, 'name' => 'Daysi', 'username' => 'daysi123', 'email' => 'daysi123@gmail.com',
                'password' => '$2y$12$YQQmKsK3/QFuurhIAq3n2.PsyeQZwk3AK4pzJuruus1oALMXotWlO',
                'pin' => null, 'role_id' => 2, 'estacion_id' => 1,
                'created_at' => '2026-08-26 23:07:08', 'updated_at' => '2026-08-26 23:17:40',
            ],
            [
                'id' => 4, 'name' => 'emilia', 'username' => 'emilia546', 'email' => 'emilia546@gmail.com',
                'password' => '$2y$12$rQ3KzBOsQGgOgkod6v2zVeW2lzkwaT1vulQc1lfPkToyFvq8cfvze',
                'pin' => null, 'role_id' => 2, 'estacion_id' => 1,
                'created_at' => '2026-08-26 23:07:08', 'updated_at' => '2026-08-26 23:24:21',
            ],
            [
                'id' => 5, 'name' => 'Toni', 'username' => 'toni546', 'email' => 'Toni546@gmail.com',
                'password' => '$2y$12$kUQymvro8qsJPdwA7IpgPecy9CutDaDn39UJ0UMA6YNFpnER4RBzG',
                'pin' => null, 'role_id' => 2, 'estacion_id' => 2,
                'created_at' => '2026-08-26 23:07:09', 'updated_at' => '2026-08-26 23:24:09',
            ],
            [
                'id' => 6, 'name' => 'Sam', 'username' => 'sam546', 'email' => 'sam546@gmail.com',
                'password' => '$2y$12$goLOpC378KU8uOYzqKZCZuZhyizItOJFnet352Lc2sYlEWkOXBiE6',
                'pin' => '$2y$12$t5VnQ4dWrZAOqzduel4/MebxFgqe70pqeNSX89ZJb6Ex08uF6GE6C',
                'role_id' => 3, 'estacion_id' => 4,
                'created_at' => '2026-08-26 23:07:09', 'updated_at' => '2026-08-26 23:25:17',
            ],
            [
                'id' => 7, 'name' => 'Marco', 'username' => 'marco546', 'email' => 'marco546@gmail.com',
                'password' => '$2y$12$PJYwmFPsTu5.9OO47oH2UOzoeFO/BjOIjt8XWsLDVN3m2Fh7HqOla',
                'pin' => '$2y$12$/5juYgQhfrEQnxVATW1lremC8QI08xiOrwSIwFSThoUY38NRLijhG',
                'role_id' => 3, 'estacion_id' => 4,
                'created_at' => '2026-08-26 23:27:34', 'updated_at' => '2026-08-26 23:27:34',
            ],
        ];

        $usuarios = array_map(fn (array $usuario) => [
            ...$usuario,
            'email_verified_at' => null,
            'remember_token' => null,
        ], $usuarios);

        DB::table('users')->upsert($usuarios, ['id']);

        $perfiles = [
            [1, 2, 'Av. Simon Lopez', '70404505', '2026-08-26 23:07:08'],
            [2, 3, 'Av. Simon Lopez', '70404505', '2026-08-26 23:07:08'],
            [3, 4, 'Av. Simon Lopez', '70404505', '2026-08-26 23:07:08'],
            [4, 5, 'Av. Simon Lopez', '70404505', '2026-08-26 23:07:09'],
            [5, 6, 'Av. Simon Lopez', '70404505', '2026-08-26 23:07:09'],
            [6, 7, 'Av. Santiago', '45665468', '2026-08-26 23:27:34'],
        ];

        $registros = array_map(fn (array $perfil) => [
            'id' => $perfil[0],
            'user_id' => $perfil[1],
            'direccion' => $perfil[2],
            'numero_celular' => $perfil[3],
            'avatar' => null,
            'created_at' => $perfil[4],
            'updated_at' => $perfil[4],
        ], $perfiles);

        DB::table('perfil_usuarios')->upsert(
            $registros,
            ['id'],
            ['user_id', 'direccion', 'numero_celular', 'avatar', 'created_at', 'updated_at']
        );
    }
}
