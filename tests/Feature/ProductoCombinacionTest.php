<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\EstacionTrabajo;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductoCombinacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_opcion_puede_compartirse_entre_combinaciones_del_mismo_producto(): void
    {
        $role = Role::create(['nombre' => 'Administrador']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $categoria = Categoria::create(['nombre' => 'Pescados']);
        $estacion = EstacionTrabajo::create(['nombre' => 'Cocina', 'codigo' => 'COCINA', 'activa' => true, 'orden' => 1]);
        $grupo = Modificador::create(['nombre' => 'Guarniciones', 'tipo' => 'multiple', 'requerido' => false, 'activo' => true, 'estacion_id' => $estacion->id]);
        $ensalada = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Ensalada', 'precio_extra' => 0, 'activo' => true, 'maneja_stock' => false]);
        $mote = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Mote', 'precio_extra' => 0, 'activo' => true, 'maneja_stock' => false]);
        $arroz = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Arroz batido', 'precio_extra' => 0, 'activo' => true, 'maneja_stock' => false]);

        $response = $this->withToken(JWTAuth::fromUser($user))->postJson('/api/productos', [
            'categoria_id' => $categoria->id,
            'estacion_id' => $estacion->id,
            'nombre' => 'Pescado',
            'precio' => 50,
            'activo' => true,
            'maneja_stock' => false,
            'opciones' => [
                ['id' => $ensalada->id, 'predeterminado' => true],
                ['id' => $mote->id, 'predeterminado' => true],
                ['id' => $arroz->id, 'predeterminado' => false],
            ],
            'modificadores' => [['id' => $grupo->id]],
            'combinaciones_configuradas' => true,
            'combinaciones' => [
                ['nombre' => 'Opción 1', 'activo' => true, 'predeterminada' => true, 'opcion_ids' => [$ensalada->id, $mote->id]],
                ['nombre' => 'Opción 2', 'activo' => true, 'predeterminada' => false, 'opcion_ids' => [$ensalada->id, $arroz->id]],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'producto.combinaciones');
        $this->assertDatabaseCount('producto_combinaciones', 2);
        $this->assertDatabaseCount('producto_combinacion_opciones', 4);
    }
}
