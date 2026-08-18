<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\EstacionTrabajo;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\PagoOrden;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_calcula_periodo_y_mantiene_operacion_e_inventario_actuales(): void
    {
        $admin = User::factory()->create(['role_id' => Role::create(['nombre' => 'Administrador'])->id]);
        $categoria = Categoria::create(['nombre' => 'Platos']);
        $cocina = EstacionTrabajo::create(['nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1]);
        $parrilla = EstacionTrabajo::create(['nombre' => 'Parrilla', 'codigo' => 'PARRILLA', 'activa' => true, 'orden' => 2]);
        $pescado = Producto::create([
            'categoria_id' => $categoria->id, 'estacion_id' => $parrilla->id, 'nombre' => 'Pescado',
            'precio' => 50, 'activo' => true, 'maneja_stock' => true, 'stock' => 2, 'stock_minimo' => 3,
        ]);
        $sopa = Producto::create([
            'categoria_id' => $categoria->id, 'estacion_id' => $cocina->id, 'nombre' => 'Sopa',
            'precio' => 20, 'activo' => true, 'maneja_stock' => false,
        ]);
        $orden = Orden::create([
            'user_id' => $admin->id, 'numero_orden' => 10, 'subtotal' => 120, 'descuento' => 0,
            'total' => 120, 'estado' => 'preparando', 'estado_pago' => 'parcial', 'tipo_flujo' => 'normal',
        ]);
        OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $pescado->id,
            'estacion_id' => $parrilla->id, 'cantidad' => 2, 'precio_unitario' => 50, 'estado_cocina' => 'pendiente']);
        OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $sopa->id,
            'estacion_id' => $cocina->id, 'cantidad' => 1, 'precio_unitario' => 20, 'estado_cocina' => 'pendiente']);

        PagoOrden::create(['id_orden' => $orden->id, 'monto_recibido' => 80, 'monto_pagado' => 80,
            'cambio_devuelto' => 0, 'metodo_pago' => 'qr', 'tipo_pago' => 'pago', 'fecha_pago' => now()]);
        PagoOrden::create(['id_orden' => $orden->id, 'monto_recibido' => 20, 'monto_pagado' => 20,
            'cambio_devuelto' => 0, 'metodo_pago' => 'efectivo', 'tipo_pago' => 'pago', 'fecha_pago' => now()]);
        PagoOrden::create(['id_orden' => $orden->id, 'monto_recibido' => -10, 'monto_pagado' => -10,
            'cambio_devuelto' => 0, 'metodo_pago' => 'qr', 'tipo_pago' => 'devolucion', 'fecha_pago' => now()]);
        Orden::create(['user_id' => $admin->id, 'numero_orden' => 11, 'subtotal' => 0, 'descuento' => 0,
            'total' => 0, 'estado' => 'pendiente', 'estado_pago' => 'pendiente', 'tipo_flujo' => 'preorden',
            'estado_preorden' => 'programada', 'fecha_programada' => now()->addDay()]);

        $fecha = now()->toDateString();
        $this->withToken(JWTAuth::fromUser($admin))->getJson("/api/dashboard?desde={$fecha}&hasta={$fecha}")
            ->assertOk()
            ->assertJsonPath('kpis.venta_total', 90)
            ->assertJsonPath('kpis.qr', 70)
            ->assertJsonPath('kpis.efectivo', 20)
            ->assertJsonPath('kpis.cantidad_ordenes', 1)
            ->assertJsonPath('kpis.ticket_promedio', 90)
            ->assertJsonPath('operacion.ordenes_pendientes', 1)
            ->assertJsonPath('operacion.cocina_pendientes', 1)
            ->assertJsonPath('operacion.parrilla_pendientes', 1)
            ->assertJsonPath('operacion.preordenes_programadas', 1)
            ->assertJsonPath('productos_por_agotar.0.nombre', 'Pescado')
            ->assertJsonPath('productos_mas_vendidos.0.nombre', 'Pescado')
            ->assertJsonPath('productos_mas_vendidos.0.cantidad', 2);
    }

    public function test_dashboard_solo_admite_administracion_y_valida_periodo(): void
    {
        $admin = User::factory()->create(['role_id' => Role::create(['nombre' => 'Administrador'])->id]);
        $mesero = User::factory()->create(['role_id' => Role::create(['nombre' => 'Mesero'])->id]);

        $this->withToken(JWTAuth::fromUser($mesero))->getJson('/api/dashboard?desde=2026-08-01&hasta=2026-08-18')
            ->assertForbidden();
        $this->withToken(JWTAuth::fromUser($admin))->getJson('/api/dashboard?desde=2026-08-18&hasta=2026-08-01')
            ->assertUnprocessable();
    }
}
