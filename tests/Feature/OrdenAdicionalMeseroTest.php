<?php

namespace Tests\Feature;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\EstacionTrabajo;
use App\Models\Mesa;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrdenAdicionalMeseroTest extends TestCase
{
    use RefreshDatabase;

    public function test_mesero_busca_y_agrega_sin_poder_alterar_precio(): void
    {
        Event::fake([OrdenCocinaActualizadaEvent::class]);
        [$mesero, $orden, $producto] = $this->escenario();
        $modificador = Modificador::create(['nombre' => 'Guarnición', 'tipo' => 'unico', 'requerido' => true, 'activo' => true]);
        $opcion = ModificadorOpcion::create(['modificador_id' => $modificador->id, 'nombre' => 'Mote', 'precio_extra' => 0, 'activo' => true]);
        $producto->opciones()->attach($opcion->id, ['predeterminado' => false]);
        OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => $producto->estacion_id, 'cantidad' => 1, 'precio_unitario' => 20]);
        $orden->update(['estado' => 'listo']);
        $token = JWTAuth::fromUser($mesero);

        $this->withToken($token)->getJson('/api/servicio/ordenes/buscar?q=Ana')
            ->assertOk()->assertJsonPath('ordenes.0.id', $orden->id);

        $this->withToken($token)->postJson("/api/servicio/ordenes/{$orden->id}/adicionales", [
            'producto_id' => $producto->id, 'cantidad' => 2, 'precio_unitario' => 1,
            'subtotal' => 1, 'total' => 1, 'modificador_opcion_ids' => [],
        ])->assertCreated()->assertJsonPath('orden.total', '100.00');

        $this->assertDatabaseCount('orden_detalles', 3);
        $this->assertDatabaseHas('orden_detalles', ['orden_id' => $orden->id, 'precio_unitario' => '40.00']);
        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'stock' => 8]);
        $this->assertDatabaseHas('ordenes', ['id' => $orden->id, 'estado' => 'preparando', 'estado_pago' => 'pendiente']);
        $this->assertDatabaseCount('historial_cambios_orden', 2);
        $this->assertDatabaseHas('historial_cambios_orden', ['orden_id' => $orden->id, 'user_id' => $mesero->id, 'tipo_cambio' => 'detalle_agregado']);
        $this->assertDatabaseCount('orden_detalle_estaciones', 3);
        Event::assertDispatched(OrdenCocinaActualizadaEvent::class, fn ($event) => $event->ordenId === $orden->id);

        $cajaRole = Role::create(['nombre' => 'Cajero']);
        $cajero = User::factory()->create(['role_id' => $cajaRole->id]);
        $this->withToken(JWTAuth::fromUser($cajero))->getJson('/api/ordenes')
            ->assertOk()->assertJsonPath('ordenes.0.id', $orden->id)
            ->assertJsonPath('ordenes.0.ultimo_cambio_mesero_en', fn ($value) => filled($value));
    }

    public function test_busqueda_incluye_ordenes_historicas_por_numero_cliente_y_mesa(): void
    {
        [$mesero, $orden] = $this->escenario();
        $orden->update(['estado' => 'entregado']);
        $token = JWTAuth::fromUser($mesero);

        foreach (['1050', 'Ana', '8'] as $termino) {
            $this->withToken($token)->getJson('/api/servicio/ordenes/buscar?q='.$termino)
                ->assertOk()->assertJsonPath('ordenes.0.id', $orden->id)
                ->assertJsonPath('ordenes.0.puede_agregar', false);
        }
        $this->withToken($token)->getJson('/api/servicio/ordenes/'.$orden->id)
            ->assertOk()->assertJsonPath('orden.puede_agregar', false);
    }

    public function test_preorden_programada_se_bloquea_y_activada_admite_adicional(): void
    {
        [$mesero, $orden, $producto] = $this->escenario();
        $token = JWTAuth::fromUser($mesero);
        $orden->update(['tipo_flujo' => 'preorden', 'estado_preorden' => 'programada']);

        $payload = ['producto_id' => $producto->id, 'cantidad' => 1, 'modificador_opcion_ids' => []];
        $this->withToken($token)->postJson("/api/servicio/ordenes/{$orden->id}/adicionales", $payload)->assertStatus(422);
        $orden->update(['estado_preorden' => 'activada']);
        $this->withToken($token)->postJson("/api/servicio/ordenes/{$orden->id}/adicionales", $payload)->assertCreated();
    }

    public function test_usuario_no_mesero_no_puede_usar_operacion(): void
    {
        [, $orden, $producto] = $this->escenario();
        $role = Role::create(['nombre' => 'Despacho']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->withToken(JWTAuth::fromUser($user))->postJson("/api/servicio/ordenes/{$orden->id}/adicionales", [
            'producto_id' => $producto->id, 'cantidad' => 1,
        ])->assertForbidden();

        $meseroRole = Role::where('nombre', 'Mesero')->firstOrFail();
        $mesero = User::factory()->create(['role_id' => $meseroRole->id]);
        $tokenMesero = JWTAuth::fromUser($mesero);
        $this->withToken($tokenMesero)->postJson("/api/ordenes/{$orden->id}/activar-preorden")->assertForbidden();
        $this->withToken($tokenMesero)->postJson('/api/pagos-ordenes', [])->assertForbidden();
    }

    private function escenario(): array
    {
        $role = Role::create(['nombre' => 'Mesero']);
        $mesero = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Ana Cliente']);
        $mesa = Mesa::create(['numero' => '8', 'capacidad' => 4, 'estado' => 'ocupada']);
        $categoria = Categoria::create(['nombre' => 'Platos']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Parrilla', 'codigo' => 'PARRILLA', 'activa' => true, 'orden' => 1]);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'estacion_id' => $estacion->id,
            'nombre' => 'Pescado', 'precio' => 40, 'activo' => true, 'maneja_stock' => true, 'stock' => 10, 'stock_minimo' => 1]);
        $orden = Orden::create(['user_id' => $mesero->id, 'cliente_id' => $cliente->id, 'mesa_id' => $mesa->id,
            'numero_orden' => 1050, 'subtotal' => 20, 'descuento' => 0, 'total' => 20, 'estado' => 'pendiente', 'estado_pago' => 'pendiente']);
        return [$mesero, $orden, $producto];
    }
}
