<?php
namespace Tests\Feature;

use App\Models\{Categoria, Cliente, EstacionTrabajo, Modificador, ModificadorOpcion, Producto, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CocinaPedidosTest extends TestCase
{
    use RefreshDatabase;

    public function test_pedido_nuevo_conserva_producto_categoria_y_guarniciones_en_ambas_estaciones(): void
    {
        $role = Role::create(['nombre' => 'Administrador']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $cliente = Cliente::create(['nombre' => 'Cliente prueba']);
        $categoria = Categoria::create(['nombre' => 'Platos']);
        $cocina = EstacionTrabajo::firstOrCreate(['codigo' => 'COCINA'], ['nombre' => 'Cocina', 'activa' => true, 'orden' => 1]);
        $parrilla = EstacionTrabajo::firstOrCreate(['codigo' => 'PARRILLA'], ['nombre' => 'Parrilla', 'activa' => true, 'orden' => 2]);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'estacion_id' => $parrilla->id,
            'nombre' => 'Carne', 'precio' => 30, 'activo' => true, 'maneja_stock' => false]);
        $modificador = Modificador::create(['nombre' => 'Guarnicion', 'tipo' => 'unico',
            'requerido' => false, 'activo' => true, 'estacion_id' => $cocina->id]);
        $opcion = ModificadorOpcion::create(['modificador_id' => $modificador->id, 'nombre' => 'Arroz',
            'precio_extra' => 0, 'activo' => true, 'maneja_stock' => false]);
        $producto->opciones()->attach($opcion->id);
        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/ordenes', [
            'cliente_id' => $cliente->id, 'tipo_orden' => 'to-go', 'subtotal' => 30, 'total' => 30,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 30,
                'modificadores' => [['modificador_opcion_id' => $opcion->id, 'precio_extra' => 0]]]],
        ])->assertCreated();
        foreach (['COCINA', 'PARRILLA'] as $estacion) {
            $response = $this->getJson('/api/kds/pedidos?fecha='.now()->toDateString().'&estacion='.$estacion)
                ->assertOk()->assertJsonCount(1, 'ordenes')
                ->assertJsonPath('ordenes.0.detalles.0.producto.nombre', 'Carne')
                ->assertJsonPath('ordenes.0.detalles.0.producto.categoria.nombre', 'Platos');
            if ($estacion === 'COCINA') {
                $response->assertJsonPath('ordenes.0.detalles.0.opciones.0.modificador_opcion.nombre', 'Arroz')
                    ->assertJsonPath('ordenes.0.detalles.0.bloqueado', true);
            }
        }
    }

    public function test_consulta_parcial_equivale_al_tablero_y_no_incluye_otras_fichas(): void
    {
        $this->test_pedido_nuevo_conserva_producto_categoria_y_guarniciones_en_ambas_estaciones();
        $producto = Producto::where('nombre', 'Carne')->firstOrFail();
        $opcion = ModificadorOpcion::where('nombre', 'Arroz')->firstOrFail();
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/ordenes', [
                'cliente_id' => Cliente::first()->id, 'tipo_orden' => 'to-go', 'subtotal' => 30, 'total' => 30,
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio_unitario' => 30,
                    'modificadores' => [['modificador_opcion_id' => $opcion->id, 'precio_extra' => 0]]]],
            ])->assertCreated();
        }
        foreach (['COCINA', 'PARRILLA'] as $estacion) {
            $url = '/api/kds/pedidos?fecha='.now()->toDateString().'&estacion='.$estacion;
            $completa = $this->getJson($url)->assertOk()->assertJsonCount(10, 'ordenes');
            $orden = $completa->json('ordenes.0');
            $parcial = $this->getJson($url.'&orden_ids[]='.$orden['id'])->assertOk()->assertJsonCount(1, 'ordenes');
            $this->assertSame($orden, $parcial->json('ordenes.0'));
            $this->assertSame([$orden['id']], $parcial->json('orden_ids'));
            $this->assertLessThan(strlen($completa->getContent()) / 3, strlen($parcial->getContent()));
            $this->getJson($url.'&orden_ids[]=999999')->assertOk()->assertJsonCount(0, 'ordenes');
            $this->getJson($url.'&orden_ids[]=invalido')->assertUnprocessable();
            $this->getJson($url.'&orden_ids[]='.$orden['id'].'&fecha=2000-01-01')->assertOk()->assertJsonCount(0, 'ordenes');
        }
    }

    public function test_consulta_parcial_respeta_la_estacion_del_usuario(): void
    {
        $this->test_pedido_nuevo_conserva_producto_categoria_y_guarniciones_en_ambas_estaciones();
        $role = Role::create(['nombre' => 'Cocinero']);
        $user = User::factory()->create(['role_id' => $role->id, 'estacion_id' => EstacionTrabajo::where('codigo', 'COCINA')->value('id')]);
        $this->withToken(JWTAuth::fromUser($user))
            ->getJson('/api/kds/pedidos?estacion=PARRILLA&orden_ids[]=1')->assertForbidden();
    }

    public function test_asignaciones_no_agregan_consultas_por_cada_pantalla(): void
    {
        $this->test_pedido_nuevo_conserva_producto_categoria_y_guarniciones_en_ambas_estaciones();
        $service = app(\App\Services\KdsAsignacionService::class);
        $estacion = EstacionTrabajo::where('codigo', 'PARRILLA')->firstOrFail();
        $user = User::firstOrFail();
        $service->registrarSesion($user, $estacion->id);
        $medir = function () use ($service, $estacion) {
            \Illuminate\Support\Facades\DB::enableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $service->sincronizarAsignaciones($estacion->id);
            $service->asignacionesParaEstacion($estacion->id);
            $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();
            return $count;
        };
        $unaPantalla = $medir();
        foreach (range(1, 4) as $i) {
            $otro = User::factory()->create(['role_id' => $user->role_id]);
            $service->registrarSesion($otro, $estacion->id);
        }
        $this->assertSame($unaPantalla, $medir());
        $asignaciones = $service->asignacionesParaEstacion($estacion->id);
        $this->assertCount(1, $asignaciones);
        $this->assertSame($user->id, array_values($asignaciones)[0]['user_id']);
    }

    public function test_preorden_programada_y_activada_coincide_en_consulta_parcial(): void
    {
        $this->test_pedido_nuevo_conserva_producto_categoria_y_guarniciones_en_ambas_estaciones();
        $orden = \App\Models\Orden::firstOrFail();
        $orden->update(['tipo_flujo' => 'preorden', 'estado_preorden' => 'programada', 'fecha_programada' => now()->addMinutes(10)]);
        foreach (['programada', 'activada'] as $estado) {
            $orden->update(['estado_preorden' => $estado]);
            foreach (['COCINA', 'PARRILLA'] as $estacion) {
                $url = '/api/kds/pedidos?fecha='.now()->toDateString().'&estacion='.$estacion;
                $completa = $this->getJson($url)->assertOk();
                $parcial = $this->getJson($url.'&orden_ids[]='.$orden->id)->assertOk();
                $this->assertSame($completa->json('ordenes'), $parcial->json('ordenes'));
                $this->assertSame($completa->json('preordenes_programadas'), $parcial->json('preordenes_programadas'));
            }
        }
    }
}
