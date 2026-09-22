<?php
namespace Tests\Feature;

use App\Models\{Cliente, Mesa, Orden, Role, User};
use App\Events\{ServicioFichaActualizadaEvent, OrdenCocinaActualizadaEvent};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ServicioMesaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mesero_asigna_y_cambia_mesa_sin_alterar_el_pedido(): void
    {
        Event::fake([ServicioFichaActualizadaEvent::class, OrdenCocinaActualizadaEvent::class]);
        $role = Role::create(['nombre' => 'Mesero']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente']);
        $orden = Orden::create(['user_id' => $user->id, 'cliente_id' => $cliente->id, 'numero_orden' => 1,
            'tipo_orden' => 'dine-in', 'subtotal' => 30, 'total' => 30, 'estado' => 'pendiente', 'estado_pago' => 'pendiente']);
        $this->withToken(JWTAuth::fromUser($user));
        foreach ([41, 42] as $numero) {
            $mesa = Mesa::create(['numero' => (string) $numero, 'capacidad' => 4, 'estado' => 'libre', 'posicion_x' => 20, 'posicion_y' => 30]);
            $this->patchJson('/api/servicio/fichas/'.$orden->id.'/mesa', ['mesa_id' => $mesa->id])
                ->assertOk()->assertJsonPath('mesa', (string) $numero);
            $this->assertSame($mesa->id, $orden->fresh()->mesa_id);
            $this->assertEquals(30, $orden->fresh()->total);
            $this->assertSame('pendiente', $orden->fresh()->estado);
        }
        $this->getJson('/api/servicio/mesas')->assertOk()->assertJsonFragment(['numero' => '42', 'posicion_x' => 20, 'posicion_y' => 30]);
        Event::assertDispatchedTimes(ServicioFichaActualizadaEvent::class, 2);
        Event::assertDispatchedTimes(OrdenCocinaActualizadaEvent::class, 2);
        $this->patchJson('/api/servicio/fichas/'.$orden->id.'/mesa', ['mesa_id' => 99999])->assertUnprocessable();
        foreach (['cancelado', 'entregado'] as $estado) {
            $orden->update(['estado' => $estado]);
            $this->patchJson('/api/servicio/fichas/'.$orden->id.'/mesa', ['mesa_id' => $mesa->id])->assertUnprocessable();
        }
        $orden->update(['estado' => 'pendiente', 'tipo_orden' => 'delivery']);
        $this->patchJson('/api/servicio/fichas/'.$orden->id.'/mesa', ['mesa_id' => $mesa->id])->assertUnprocessable();
        $admin = User::factory()->create(['role_id' => Role::create(['nombre' => 'Administrador'])->id]);
        $this->withToken(JWTAuth::fromUser($admin))->patchJson('/api/servicio/fichas/'.$orden->id.'/mesa', ['mesa_id' => $mesa->id])->assertForbidden();
    }
}
