<?php

namespace Tests\Feature;

use App\Events\ServicioFichaActualizadaEvent;
use App\Models\Orden;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DeliveryChangePreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cajero_prepara_y_anula_el_cambio_de_un_delivery(): void
    {
        Event::fake([ServicioFichaActualizadaEvent::class]);
        $cajero = User::factory()->create([
            'role_id' => Role::create(['nombre' => 'Cajero'])->id,
        ]);
        $orden = Orden::create([
            'user_id' => $cajero->id,
            'numero_orden' => 24,
            'tipo_orden' => 'delivery',
            'fecha_orden' => now(),
            'subtotal' => 73,
            'total' => 73,
            'estado' => 'pendiente',
            'estado_pago' => 'pendiente',
        ]);
        $token = JWTAuth::fromUser($cajero);

        $this->withToken($token)->patchJson("/api/ordenes/{$orden->id}/delivery-cambio", [
            'preparado' => true,
            'monto_esperado' => 100,
        ])->assertOk()
            ->assertJsonPath('saldo_pendiente', 73)
            ->assertJsonPath('delivery_cambio_preparado', true)
            ->assertJsonPath('delivery_cambio', 27);

        $this->assertDatabaseHas('ordenes', [
            'id' => $orden->id,
            'delivery_monto_esperado' => 100,
            'delivery_cambio_preparado' => true,
            'delivery_cambio_preparado_por' => $cajero->id,
        ]);

        $this->withToken($token)->patchJson("/api/ordenes/{$orden->id}/delivery-cambio", [
            'preparado' => false,
        ])->assertOk()->assertJsonPath('delivery_cambio_preparado', false);

        $this->assertNull($orden->fresh()->delivery_monto_esperado);
        Event::assertDispatchedTimes(ServicioFichaActualizadaEvent::class, 2);
    }

    public function test_rechaza_monto_insuficiente_y_orden_que_no_es_delivery(): void
    {
        Event::fake([ServicioFichaActualizadaEvent::class]);
        $cajero = User::factory()->create([
            'role_id' => Role::create(['nombre' => 'Cajero'])->id,
        ]);
        $token = JWTAuth::fromUser($cajero);
        $delivery = Orden::create([
            'user_id' => $cajero->id, 'numero_orden' => 25, 'tipo_orden' => 'delivery',
            'fecha_orden' => now(), 'subtotal' => 73, 'total' => 73,
        ]);
        $mesa = Orden::create([
            'user_id' => $cajero->id, 'numero_orden' => 26, 'tipo_orden' => 'dine-in',
            'fecha_orden' => now(), 'subtotal' => 20, 'total' => 20,
        ]);

        $this->withToken($token)->patchJson("/api/ordenes/{$delivery->id}/delivery-cambio", [
            'preparado' => true, 'monto_esperado' => 50,
        ])->assertUnprocessable();

        $this->withToken($token)->patchJson("/api/ordenes/{$mesa->id}/delivery-cambio", [
            'preparado' => true, 'monto_esperado' => 20,
        ])->assertUnprocessable();
    }
}
