<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\EstacionTrabajo;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PedidoLlamadaMeseroTest extends TestCase
{
    use RefreshDatabase;

    public function test_mesero_puede_crear_pedido_inmediato_solo_con_marca_de_llamada(): void
    {
        [$mesero, $cliente, $producto] = $this->escenario();
        $payload = $this->payload($cliente, $producto);
        $payload['pedido_llamada_inmediato'] = true;

        $this->withToken(JWTAuth::fromUser($mesero))->postJson('/api/ordenes', $payload)
            ->assertCreated()
            ->assertJsonPath('orden.tipo_flujo', 'normal');

        $this->assertDatabaseHas('ordenes', [
            'cliente_id' => $cliente->id,
            'tipo_orden' => 'delivery',
            'tipo_flujo' => 'normal',
            'estado_preorden' => null,
        ]);
    }

    public function test_mesero_no_puede_crear_orden_normal_fuera_del_flujo_de_llamada(): void
    {
        [$mesero, $cliente, $producto] = $this->escenario();

        $this->withToken(JWTAuth::fromUser($mesero))->postJson('/api/ordenes', $this->payload($cliente, $producto))
            ->assertForbidden();

        $this->assertDatabaseCount('ordenes', 0);
    }

    private function escenario(): array
    {
        $role = Role::create(['nombre' => 'Mesero']);
        $mesero = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente por llamada']);
        $categoria = Categoria::create(['nombre' => 'Pollos']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'estacion_id' => $estacion->id,
            'nombre' => 'Pollo de una presa',
            'precio' => 17,
            'activo' => true,
            'maneja_stock' => false,
        ]);

        return [$mesero, $cliente, $producto];
    }

    private function payload(Cliente $cliente, Producto $producto): array
    {
        return [
            'cliente_id' => $cliente->id,
            'tipo_orden' => 'delivery',
            'tipo_flujo' => 'normal',
            'subtotal' => 17,
            'total' => 17,
            'items' => [[
                'producto_id' => $producto->id,
                'cantidad' => 1,
                'precio_unitario' => 17,
                'modificadores' => [],
            ]],
        ];
    }
}
