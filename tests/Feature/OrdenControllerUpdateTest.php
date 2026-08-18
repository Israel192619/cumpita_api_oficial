<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Mesa;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use App\Models\EstacionTrabajo;
use App\Models\OrdenDetalle;
use App\Events\OrdenCocinaActualizadaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrdenControllerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_an_existing_order_with_the_sent_fields_and_items(): void
    {
        $role = Role::create([
            'nombre' => 'Cajero',
            'descripcion' => 'Acceso operativo a POS y Caja',
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
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
        $estacion = EstacionTrabajo::create([
            'nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1,
        ]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'estacion_id' => $estacion->id,
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
            'numero_orden' => 1,
            'cliente_id' => $cliente->id,
            'mesa_id' => $mesa->id,
            'fecha_orden' => '2026-07-08 10:00:00',
            'subtotal' => 10000,
            'descuento' => 0,
            'total' => 10000,
            'estado' => 'pendiente',
            'observaciones' => 'Original',
            'tipo_orden' => 'dine-in',
        ]);

        $token = JWTAuth::fromUser($user);

        $payload = [
            'cliente_id' => $cliente->id,
            'mesa_id' => $mesa->id,
            'tipo_orden' => 'delivery',
            'fecha_orden' => '2026-07-08T14:30:00',
            'subtotal' => 24000,
            'descuento' => 2000,
            'total' => 22000,
            'estado' => 'preparando',
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
        $response->assertJsonPath('orden.estado_pago', 'pendiente');

        $this->assertDatabaseHas('ordenes', [
            'id' => $orden->id,
            'tipo_orden' => 'delivery',
            'estado' => 'preparando',
            'observaciones' => 'Actualizada',
            'subtotal' => '24000.00',
            'descuento' => '2000.00',
            'total' => '22000.00',
        ]);

        $this->assertDatabaseCount('orden_detalles', 2);
        $this->assertDatabaseHas('orden_detalles', [
            'orden_id' => $orden->id,
            'producto_id' => $producto->id,
            'cantidad' => 1,
            'nota' => 'Sin hielo',
        ]);
    }

    public function test_aumentar_cantidad_crea_solo_unidades_nuevas_pendientes(): void
    {
        Event::fake([OrdenCocinaActualizadaEvent::class]);
        $role = Role::create(['nombre' => 'Cajero']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente cantidad']);
        $categoria = Categoria::create(['nombre' => 'Parrilla']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Parrilla', 'codigo' => 'PARRILLA', 'activa' => true, 'orden' => 1]);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'estacion_id' => $estacion->id,
            'nombre' => 'Pescado', 'precio' => 40, 'activo' => true, 'maneja_stock' => true, 'stock' => 10, 'stock_minimo' => 1]);
        $orden = Orden::create(['user_id' => $user->id, 'cliente_id' => $cliente->id, 'numero_orden' => 2,
            'subtotal' => 40, 'total' => 40, 'estado' => 'listo', 'estado_pago' => 'pendiente']);
        $detalleListo = OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id,
            'estacion_id' => $estacion->id, 'cantidad' => 1, 'precio_unitario' => 40, 'estado_cocina' => 'servido']);
        $detalleListo->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);

        $this->withToken(JWTAuth::fromUser($user))->putJson('/api/ordenes/'.$orden->id, [
            'cliente_id' => $cliente->id, 'subtotal' => 80, 'total' => 80,
            'items' => [[
                'orden_detalle_id' => $detalleListo->id,
                'producto_id' => $producto->id,
                'cantidad' => 2,
                'precio_unitario' => 40,
                'modificadores' => [],
            ]],
        ])->assertOk();

        $detalles = $orden->detalles()->with('estadosEstacion')->orderBy('id')->get();
        $this->assertCount(2, $detalles);
        $this->assertSame('servido', $detalles[0]->estadosEstacion->first()->estado);
        $this->assertSame('pendiente', $detalles[1]->estadosEstacion->first()->estado);
        $this->assertSame(1, $detalles[0]->cantidad);
        $this->assertSame(1, $detalles[1]->cantidad);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 9]);
        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'preparando', 'total' => '80.00']);
        $this->assertDatabaseHas('historial_cambios_orden', ['orden_id' => $orden->id,
            'orden_detalle_id' => $detalles[1]->id, 'tipo_cambio' => 'detalle_agregado', 'cantidad_nueva' => 1]);
        Event::assertDispatched(OrdenCocinaActualizadaEvent::class, fn ($event) => $event->ordenId === $orden->id);

        $this->withToken(JWTAuth::fromUser($user))->putJson('/api/ordenes/'.$orden->id, [
            'cliente_id' => $cliente->id, 'subtotal' => 80, 'total' => 80,
            'items' => $detalles->map(fn ($detalle) => ['orden_detalle_id' => $detalle->id,
                'producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 40, 'modificadores' => []])->all(),
        ])->assertOk();
        $this->assertCount(2, $orden->detalles()->get());
        $this->assertSame(1, $orden->historialCambios()->where('tipo_cambio', 'detalle_agregado')->count());

        // 2 -> 5: las dos unidades existentes se conservan y nacen exactamente tres.
        $detalles[1]->update(['estado_cocina' => 'servido']);
        $detalles[1]->estadosEstacion()->update(['estado' => 'servido', 'fecha_servido' => now()]);
        $this->withToken(JWTAuth::fromUser($user))->putJson('/api/ordenes/'.$orden->id, [
            'cliente_id' => $cliente->id, 'subtotal' => 200, 'total' => 200,
            'items' => [
                ['orden_detalle_id' => $detalles[0]->id, 'producto_id' => $producto->id, 'cantidad' => 4, 'precio_unitario' => 40, 'modificadores' => []],
                ['orden_detalle_id' => $detalles[1]->id, 'producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 40, 'modificadores' => []],
            ],
        ])->assertOk();

        $cinco = $orden->detalles()->with('estadosEstacion')->orderBy('id')->get();
        $this->assertCount(5, $cinco);
        $this->assertSame(2, $cinco->filter(fn ($detalle) => $detalle->estadosEstacion->first()?->estado === 'servido')->count());
        $this->assertSame(3, $cinco->filter(fn ($detalle) => $detalle->estadosEstacion->first()?->estado === 'pendiente')->count());
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 6]);
    }
}
