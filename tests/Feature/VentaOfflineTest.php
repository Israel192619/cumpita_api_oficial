<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\EstacionTrabajo;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class VentaOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_orden_y_pago_en_una_sola_operacion(): void
    {
        [$cajero, $cliente, $producto] = $this->escenario();
        $caja = Caja::create(['user_id' => $cajero->id, 'monto_apertura' => 100, 'estado' => 'abierta']);
        $payload = $this->payload($cajero, $cliente, $producto, (string) Str::uuid(), $caja->id);

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/ordenes', $payload)
            ->assertCreated()
            ->assertJsonPath('orden.estado_pago', 'completado')
            ->assertJsonPath('orden.pagos.0.monto_pagado', 50);

        $this->assertDatabaseCount('ordenes', 1);
        $this->assertDatabaseCount('pagos_ordenes', 1);
        $this->assertDatabaseHas('pagos_ordenes', ['caja_id' => $caja->id, 'monto_recibido' => 100, 'cambio_devuelto' => 50]);
    }

    public function test_reintentar_la_misma_venta_no_duplica_orden_pago_ni_stock(): void
    {
        [$cajero, $cliente, $producto] = $this->escenario(true);
        $caja = Caja::create(['user_id' => $cajero->id, 'monto_apertura' => 100, 'estado' => 'abierta']);
        $payload = $this->payload($cajero, $cliente, $producto, (string) Str::uuid(), $caja->id);
        $token = JWTAuth::fromUser($cajero);

        $primera = $this->withToken($token)->postJson('/api/ordenes', $payload)->assertCreated();
        $segunda = $this->withToken($token)->postJson('/api/ordenes', $payload)
            ->assertOk()->assertJsonPath('duplicada', true);

        $this->assertSame($primera->json('orden.id'), $segunda->json('orden.id'));
        $this->assertDatabaseCount('ordenes', 1);
        $this->assertDatabaseCount('pagos_ordenes', 1);
        $this->assertSame(4, (int) $producto->fresh()->stock);
    }

    public function test_un_error_de_pago_no_deja_una_orden_incompleta(): void
    {
        [$cajero, $cliente, $producto] = $this->escenario();
        $payload = $this->payload($cajero, $cliente, $producto, (string) Str::uuid(), null);

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/ordenes', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('ordenes', 0);
        $this->assertDatabaseCount('orden_detalles', 0);
        $this->assertDatabaseCount('pagos_ordenes', 0);
    }

    public function test_venta_guardada_puede_sincronizarse_si_la_caja_se_cerro_despues(): void
    {
        [$cajero, $cliente, $producto] = $this->escenario();
        $caja = Caja::create(['user_id' => $cajero->id, 'monto_apertura' => 100, 'estado' => 'cerrada']);
        $payload = $this->payload($cajero, $cliente, $producto, (string) Str::uuid(), $caja->id);
        $payload['venta_sin_conexion'] = true;

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/ordenes', $payload)
            ->assertCreated()->assertJsonPath('orden.estado_pago', 'completado');

        $this->assertDatabaseHas('pagos_ordenes', ['caja_id' => $caja->id, 'monto_pagado' => 50]);
    }

    private function escenario(bool $conStock = false): array
    {
        $role = Role::create(['nombre' => 'Cajero']);
        $cajero = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente offline']);
        $categoria = Categoria::create(['nombre' => 'Pescados']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'estacion_id' => $estacion->id,
            'nombre' => 'Pescado grande',
            'precio' => 50,
            'activo' => true,
            'maneja_stock' => $conStock,
            'stock' => $conStock ? 5 : null,
        ]);
        return [$cajero, $cliente, $producto];
    }

    private function payload(User $cajero, Cliente $cliente, Producto $producto, string $operacionId, ?int $cajaId): array
    {
        return [
            'operacion_cliente_id' => $operacionId,
            'usuario_origen_id' => $cajero->id,
            'caja_id' => $cajaId,
            'venta_sin_conexion' => false,
            'cliente_id' => $cliente->id,
            'tipo_orden' => 'dine-in',
            'fecha_orden' => now()->format('Y-m-d\\TH:i:s'),
            'subtotal' => 50,
            'total' => 50,
            'items' => [[
                'producto_id' => $producto->id,
                'cantidad' => 1,
                'precio_unitario' => 50,
                'modificadores' => [],
            ]],
            'pagos' => [[
                'metodo_pago' => 'efectivo',
                'monto_aplicado' => 50,
                'monto_recibido' => 100,
            ]],
        ];
    }
}
