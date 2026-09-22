<?php

namespace Tests\Feature;

use App\Events\StockActualizadoEvent;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\EstacionTrabajo;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ModificadorStockRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_cualquier_aumento_o_disminucion_publica_el_stock_final(): void
    {
        Event::fake([StockActualizadoEvent::class]);
        $modificador = Modificador::create([
            'nombre' => 'Presas', 'tipo' => 'unico', 'requerido' => false, 'activo' => true,
        ]);
        $opcion = ModificadorOpcion::create([
            'modificador_id' => $modificador->id, 'nombre' => 'Ala', 'precio_extra' => 0,
            'activo' => true, 'maneja_stock' => true, 'stock' => 5, 'stock_minimo' => 1,
        ]);

        $opcion->update(['stock' => 9]);
        $opcion->update(['stock' => 7]);

        Event::assertDispatched(StockActualizadoEvent::class, fn ($event) =>
            $event->modificadorOpcionId === $opcion->id && $event->stock === 9);
        Event::assertDispatched(StockActualizadoEvent::class, fn ($event) =>
            $event->modificadorOpcionId === $opcion->id && $event->stock === 7);
        Event::assertDispatchedTimes(StockActualizadoEvent::class, 2);
    }

    public function test_vender_desde_pos_publica_la_disminucion_de_la_opcion(): void
    {
        Event::fake([StockActualizadoEvent::class]);
        $role = Role::create(['nombre' => 'Cajero']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente POS']);
        $categoria = Categoria::create(['nombre' => 'Pollos']);
        $estacion = EstacionTrabajo::create([
            'nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1,
        ]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id, 'estacion_id' => $estacion->id,
            'nombre' => 'Pollo', 'precio' => 30, 'activo' => true, 'maneja_stock' => false,
        ]);
        $modificador = Modificador::create([
            'nombre' => 'Presa', 'tipo' => 'unico', 'requerido' => true, 'activo' => true,
        ]);
        $opcion = ModificadorOpcion::create([
            'modificador_id' => $modificador->id, 'nombre' => 'Pierna', 'precio_extra' => 0,
            'activo' => true, 'maneja_stock' => true, 'stock' => 5, 'stock_minimo' => 1,
        ]);
        $producto->opciones()->attach($opcion->id);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/ordenes', [
            'cliente_id' => $cliente->id,
            'tipo_orden' => 'to-go',
            'subtotal' => 60,
            'total' => 60,
            'items' => [[
                'producto_id' => $producto->id,
                'cantidad' => 2,
                'precio_unitario' => 30,
                'modificadores' => [[
                    'modificador_opcion_id' => $opcion->id,
                    'precio_extra' => 0,
                ]],
            ]],
        ])->assertCreated();

        $this->assertSame(3, $opcion->fresh()->stock);
        Event::assertDispatched(StockActualizadoEvent::class, fn ($event) =>
            $event->modificadorOpcionId === $opcion->id && $event->stock === 3);
    }
}
