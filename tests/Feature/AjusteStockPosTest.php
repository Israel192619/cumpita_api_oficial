<?php

namespace Tests\Feature;

use App\Models\AjusteStock;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AjusteStockPosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cajero_puede_reabastecer_una_opcion_desde_pos(): void
    {
        $opcion = $this->crearOpcion(0);
        $cajero = $this->crearUsuario('Cajero');

        $this->withToken(JWTAuth::fromUser($cajero))->postJson('/api/ajustes-stock', [
            'modificador_opcion_id' => $opcion->id,
            'tipo' => 'ENTRADA',
            'cantidad' => 3,
            'motivo' => 'Reabastecimiento desde POS: Ala',
        ])->assertCreated()->assertJsonPath('inventariable.stock', 3);

        $this->assertSame(3, $opcion->fresh()->stock);
        $this->assertDatabaseHas('ajustes_stock', [
            'modificador_opcion_id' => $opcion->id,
            'tipo' => 'ENTRADA',
            'cantidad' => 3,
            'usuario_id' => $cajero->id,
        ]);
    }

    public function test_cajero_no_puede_registrar_salidas_ni_correcciones_por_la_api(): void
    {
        $opcion = $this->crearOpcion(5);
        $cajero = $this->crearUsuario('Cajero');
        $token = JWTAuth::fromUser($cajero);

        foreach (['SALIDA', 'CORRECCION'] as $tipo) {
            $this->withToken($token)->postJson('/api/ajustes-stock', [
                'modificador_opcion_id' => $opcion->id,
                'tipo' => $tipo,
                'cantidad' => 1,
                'motivo' => 'Intento fuera de POS',
            ])->assertForbidden();
        }

        $this->assertSame(5, $opcion->fresh()->stock);
        $this->assertSame(0, AjusteStock::count());
    }

    public function test_un_mesero_no_puede_reabastecer_desde_pos(): void
    {
        $opcion = $this->crearOpcion(0);
        $mesero = $this->crearUsuario('Mesero');

        $this->withToken(JWTAuth::fromUser($mesero))->postJson('/api/ajustes-stock', [
            'modificador_opcion_id' => $opcion->id,
            'tipo' => 'ENTRADA',
            'cantidad' => 1,
        ])->assertForbidden();

        $this->assertSame(0, $opcion->fresh()->stock);
    }

    private function crearOpcion(int $stock): ModificadorOpcion
    {
        $modificador = Modificador::create([
            'nombre' => 'Presas',
            'tipo' => 'multiple',
            'requerido' => true,
            'activo' => true,
        ]);

        return ModificadorOpcion::create([
            'modificador_id' => $modificador->id,
            'nombre' => 'Ala',
            'precio_extra' => 0,
            'activo' => true,
            'maneja_stock' => true,
            'stock' => $stock,
            'stock_minimo' => 1,
        ]);
    }

    private function crearUsuario(string $rol): User
    {
        $role = Role::create(['nombre' => $rol]);
        return User::factory()->create(['role_id' => $role->id]);
    }
}
