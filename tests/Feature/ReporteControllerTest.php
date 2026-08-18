<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\EstacionTrabajo;
use App\Models\GastoCaja;
use App\Models\MovimientoCaja;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PagoOrden;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReporteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_tres_reportes_respetan_el_periodo_y_fuentes_existentes(): void
    {
        $admin = User::factory()->create(['role_id' => Role::create(['nombre' => 'Administrador'])->id]);
        $categoria = Categoria::create(['nombre' => 'Platos']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1]);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'estacion_id' => $estacion->id,
            'nombre' => 'Sopa', 'precio' => 20, 'activo' => true, 'maneja_stock' => false]);
        $orden = Orden::create(['user_id' => $admin->id, 'numero_orden' => 30, 'subtotal' => 40, 'descuento' => 5,
            'total' => 35, 'estado' => 'entregado', 'estado_pago' => 'completado', 'tipo_flujo' => 'normal']);
        OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => $estacion->id,
            'cantidad' => 2, 'precio_unitario' => 20, 'estado_cocina' => 'servido']);
        $caja = Caja::create(['user_id' => $admin->id, 'monto_apertura' => 100, 'monto_esperado' => 125,
            'monto_cierre' => 124, 'diferencia' => -1, 'fecha_apertura' => now()->subHours(8), 'fecha_cierre' => now(), 'estado' => 'cerrada']);
        PagoOrden::create(['id_orden' => $orden->id, 'caja_id' => $caja->id, 'monto_recibido' => 20,
            'monto_pagado' => 20, 'cambio_devuelto' => 0, 'metodo_pago' => 'efectivo', 'tipo_pago' => 'pago', 'fecha_pago' => now()]);
        PagoOrden::create(['id_orden' => $orden->id, 'monto_recibido' => 15,
            'monto_pagado' => 15, 'cambio_devuelto' => 0, 'metodo_pago' => 'qr', 'tipo_pago' => 'pago', 'fecha_pago' => now()]);
        MovimientoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $admin->id, 'tipo' => 'INGRESO',
            'monto' => 10, 'motivo' => 'Cambio', 'estado' => 'ACTIVO']);
        GastoCaja::create(['caja_id' => $caja->id, 'usuario_id' => $admin->id, 'categoria' => 'INSUMOS',
            'concepto' => 'Compra', 'monto' => 5, 'estado' => 'ACTIVO']);

        $token = JWTAuth::fromUser($admin); $fecha = now()->toDateString();
        $this->withToken($token)->getJson("/api/reportes/ventas?desde={$fecha}&hasta={$fecha}")
            ->assertOk()->assertJsonPath('resumen.venta_total', 35)->assertJsonPath('resumen.efectivo', 20)
            ->assertJsonPath('resumen.qr', 15)->assertJsonPath('resumen.descuentos', 5)->assertJsonCount(2, 'filas');
        $this->withToken($token)->getJson("/api/reportes/productos?desde={$fecha}&hasta={$fecha}")
            ->assertOk()->assertJsonPath('resumen.productos_vendidos', 1)->assertJsonPath('resumen.unidades_vendidas', 2)
            ->assertJsonPath('resumen.total_generado', 40)->assertJsonPath('filas.0.nombre', 'Sopa');
        $this->withToken($token)->getJson("/api/reportes/caja?desde={$fecha}&hasta={$fecha}")
            ->assertOk()->assertJsonPath('resumen.efectivo_esperado', 125)->assertJsonPath('resumen.efectivo_contado', 124)
            ->assertJsonPath('resumen.diferencia', -1)->assertJsonPath('resumen.qr', 15)
            ->assertJsonPath('resumen.ingresos', 10)->assertJsonPath('resumen.gastos', 5);
    }

    public function test_reportes_requieren_administrador_y_fechas_validas(): void
    {
        $mesero = User::factory()->create(['role_id' => Role::create(['nombre' => 'Mesero'])->id]);
        $this->withToken(JWTAuth::fromUser($mesero))->getJson('/api/reportes/ventas?desde=2026-08-01&hasta=2026-08-18')->assertForbidden();
    }
}
