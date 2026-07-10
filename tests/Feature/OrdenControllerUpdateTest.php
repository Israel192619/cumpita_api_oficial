<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrdenControllerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_an_existing_order_with_the_sent_fields_and_items(): void
    {
        $user = User::factory()->create();
        $cliente = Cliente::create([
            'nombre' => 'Cliente original',
            'telefono' => '3000000000',
        ]);
        $mesa = Mesa::create([
            'numero' => '5',
            'capacidad' => 4,
            'estado' => 'libre',
        ]);
        $categoria = Categoria::create([
            'nombre' => 'Bebidas',
            'descripcion' => 'Test',
        ]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Cerveza',
            'descripcion' => 'Test',
            'precio' => 12000,
            'activo' => true,
            'maneja_stock' => false,
            'stock' => 10,
            'stock_minimo' => 2,
        ]);

        $orden = Orden::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'mesa_id' => $mesa->id,
            'fecha_orden' => '2026-07-08 10:00:00',
            'subtotal' => 10000,
            'descuento' => 0,
            'total' => 10000,
            'estado' => 'pendiente',
            'metodo_pago' => 'efectivo',
            'observaciones' => 'Original',
            'tipo_orden' => 'dine-in',
        ]);

        $token = JWTAuth::fromUser($user);

        $payload = [
            'cliente_id' => $cliente->id,
            'mesa_id' => $mesa->id,
            'tipo_orden' => 'delivery',
            'fecha_orden' => '2026-07-08T14:30',
            'subtotal' => 24000,
            'descuento' => 2000,
            'total' => 22000,
            'estado' => 'preparando',
            'metodo_pago' => 'tarjeta',
            'observaciones' => 'Actualizada',
            'items' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => 2,
                    'precio_unitario' => 12000,
                    'nota' => 'Sin hielo',
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/ordenes/' . $orden->id, $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('orden.observaciones', 'Actualizada');
        $response->assertJsonPath('orden.tipo_orden', 'delivery');
        $response->assertJsonPath('orden.metodo_pago', 'tarjeta');

        $this->assertDatabaseHas('ordenes', [
            'id' => $orden->id,
            'tipo_orden' => 'delivery',
            'estado' => 'preparando',
            'observaciones' => 'Actualizada',
            'subtotal' => '24000.00',
            'descuento' => '2000.00',
            'total' => '22000.00',
        ]);

        $this->assertDatabaseHas('orden_detalles', [
            'orden_id' => $orden->id,
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'nota' => 'Sin hielo',
        ]);
    }
}
