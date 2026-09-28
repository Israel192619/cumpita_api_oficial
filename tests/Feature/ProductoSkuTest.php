<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductoSkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_el_sku_y_no_permite_repetirlo(): void
    {
        $role = Role::create(['nombre' => 'Administrador']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $categoria = Categoria::create(['nombre' => 'Bebidas']);
        $token = JWTAuth::fromUser($user);

        $producto = [
            'categoria_id' => $categoria->id,
            'estacion_id' => null,
            'nombre' => 'Coca-Cola 2L',
            'precio' => 15,
            'sku' => ' beb-101 ',
            'activo' => true,
            'maneja_stock' => false,
        ];

        $this->withToken($token)->postJson('/api/productos', $producto)
            ->assertCreated()
            ->assertJsonPath('producto.sku', 'BEB-101');

        $this->withToken($token)->postJson('/api/productos', [
            ...$producto,
            'nombre' => 'Otra bebida',
            'sku' => 'BEB-101',
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }
}
