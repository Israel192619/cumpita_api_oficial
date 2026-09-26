<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Orden;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PagoDivididoTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_qr_y_efectivo_con_cambio_en_una_sola_operacion(): void
    {
        [$cajero, $orden] = $this->escenario(120);
        $caja = Caja::create(['user_id' => $cajero->id, 'monto_apertura' => 100, 'estado' => 'abierta']);

        $respuesta = $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/pagos-ordenes/dividido', [
            'id_orden' => $orden->id,
            'pagos' => [
                ['metodo_pago' => 'qr', 'monto_aplicado' => 70, 'monto_recibido' => 70],
                ['metodo_pago' => 'efectivo', 'monto_aplicado' => 50, 'monto_recibido' => 100],
            ],
        ]);

        $respuesta->assertCreated()->assertJsonPath('total_aplicado', 120)->assertJsonPath('saldo_pendiente', 0);
        $this->assertSame('completado', $orden->fresh()->estado_pago);
        $this->assertDatabaseHas('pagos_ordenes', [
            'id_orden' => $orden->id, 'metodo_pago' => 'qr', 'monto_pagado' => 70,
            'monto_recibido' => 70, 'cambio_devuelto' => 0, 'caja_id' => null,
        ]);
        $this->assertDatabaseHas('pagos_ordenes', [
            'id_orden' => $orden->id, 'metodo_pago' => 'efectivo', 'monto_pagado' => 50,
            'monto_recibido' => 100, 'cambio_devuelto' => 50, 'caja_id' => $caja->id,
        ]);
    }

    public function test_permite_varias_personas_y_conserva_el_saldo_parcial(): void
    {
        [$cajero, $orden] = $this->escenario(120);

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/pagos-ordenes/dividido', [
            'id_orden' => $orden->id,
            'pagos' => [
                ['metodo_pago' => 'qr', 'monto_aplicado' => 30],
                ['metodo_pago' => 'qr', 'monto_aplicado' => 40],
            ],
        ])->assertCreated()->assertJsonPath('saldo_pendiente', 50);

        $this->assertSame('parcial', $orden->fresh()->estado_pago);
        $this->assertSame(2, $orden->pagos()->count());
    }

    public function test_un_error_no_guarda_ninguna_parte_del_pago_dividido(): void
    {
        [$cajero, $orden] = $this->escenario(120);

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/pagos-ordenes/dividido', [
            'id_orden' => $orden->id,
            'pagos' => [
                ['metodo_pago' => 'qr', 'monto_aplicado' => 80],
                ['metodo_pago' => 'qr', 'monto_aplicado' => 60],
            ],
        ])->assertUnprocessable()->assertJsonPath('message', 'Los pagos divididos superan el saldo pendiente.');

        $this->assertDatabaseCount('pagos_ordenes', 0);
        $this->assertSame('pendiente', $orden->fresh()->estado_pago);
    }

    private function escenario(float $total): array
    {
        $role = Role::create(['nombre' => 'Cajero']);
        $cajero = User::factory()->create(['role_id' => $role->id]);
        $orden = Orden::create([
            'user_id' => $cajero->id,
            'numero_orden' => 1,
            'total' => $total,
            'subtotal' => $total,
            'estado' => 'pendiente',
            'estado_pago' => 'pendiente',
        ]);

        return [$cajero, $orden];
    }
}
