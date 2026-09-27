<?php

namespace Tests\Feature;

use App\Http\Controllers\OrdenController;
use App\Http\Controllers\SolicitudPreordenController;
use App\Http\Controllers\ModificadorController;
use App\Events\ProductoActualizadoEvent;
use App\Events\StockActualizadoEvent;
use App\Models\Producto;
use App\Models\ProductoModificadorConfiguracion;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LimiteGuarnicionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_presas_siguen_siendo_obligatorias_si_una_predeterminada_se_desactiva(): void
    {
        $this->seed();
        $grupo = Modificador::create(['nombre' => 'Presas de pollo', 'tipo' => 'multiple', 'requerido' => true, 'activo' => true]);
        $ala = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Ala', 'precio_extra' => 0, 'activo' => true]);
        $pecho = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Pecho', 'precio_extra' => 0, 'activo' => true]);
        $producto = Producto::findOrFail(9);
        $producto->opciones()->attach([$ala->id => ['predeterminado' => true], $pecho->id => ['predeterminado' => true]]);
        ProductoModificadorConfiguracion::create([
            'producto_id' => $producto->id, 'modificador_id' => $grupo->id,
            // Aunque un dato antiguo esté marcado como máximo, Presas debe
            // continuar exigiendo exactamente dos selecciones.
            'cantidad_requerida' => 2, 'cantidad_es_maxima' => true,
        ]);
        $ala->update(['activo' => false]);
        $producto = Producto::with(['opciones.modificador', 'configuracionesModificador'])->findOrFail(9);
        $catalogo = collect($producto->modificadores_estructurados)->firstWhere('id', $grupo->id);
        $this->assertSame([$pecho->id], collect($catalogo['opciones'])->pluck('id')->all());

        $validar = new \ReflectionMethod(OrdenController::class, 'validarModificadoresProducto');
        try {
            $validar->invoke(new OrdenController(), $producto, [['modificador_opcion_id' => $pecho->id]]);
            $this->fail('No debe aceptarse un pollo de dos presas con una sola.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exactamente 2', $error->getMessage());
        }
        $this->assertNull($validar->invoke(new OrdenController(), $producto, [
            ['modificador_opcion_id' => $pecho->id], ['modificador_opcion_id' => $pecho->id],
        ]));
        $validarPreorden = new \ReflectionMethod(SolicitudPreordenController::class, 'validarSeleccion');
        try {
            $validarPreorden->invoke(new SolicitudPreordenController(), $producto, collect([$pecho->id]));
            $this->fail('La preorden tampoco debe aceptar una sola presa.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertNull($validarPreorden->invoke(new SolicitudPreordenController(), $producto, collect([$pecho->id, $pecho->id])));
    }

    public function test_una_presa_es_obligatoria_aunque_la_configuracion_antigua_diga_maximo(): void
    {
        $this->seed();
        $grupo = Modificador::create(['nombre' => 'Presas de pollo', 'tipo' => 'multiple', 'requerido' => true, 'activo' => true]);
        $ala = ModificadorOpcion::create(['modificador_id' => $grupo->id, 'nombre' => 'Ala', 'precio_extra' => 0, 'activo' => true]);
        $producto = Producto::findOrFail(8);
        $producto->opciones()->attach($ala->id);
        ProductoModificadorConfiguracion::create([
            'producto_id' => $producto->id, 'modificador_id' => $grupo->id,
            'cantidad_requerida' => 1, 'cantidad_es_maxima' => true,
        ]);
        $producto = Producto::with(['opciones.modificador', 'configuracionesModificador'])->findOrFail(8);
        $catalogo = collect($producto->modificadores_estructurados)->firstWhere('id', $grupo->id);
        $this->assertFalse($catalogo['cantidad_es_maxima']);

        $validar = new \ReflectionMethod(OrdenController::class, 'validarModificadoresProducto');
        try {
            $validar->invoke(new OrdenController(), $producto, []);
            $this->fail('No debe aceptarse un pollo de una presa sin presa.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exactamente 1', $error->getMessage());
        }
        $this->assertNull($validar->invoke(new OrdenController(), $producto, [
            ['modificador_opcion_id' => $ala->id],
        ]));
    }

    public function test_desactivar_una_opcion_notifica_a_los_productos_afectados(): void
    {
        $this->seed();
        Event::fake([ProductoActualizadoEvent::class, StockActualizadoEvent::class]);
        $modificador = Modificador::with('opciones')->findOrFail(1);
        $opciones = $modificador->opciones->map(fn ($opcion) => [
            'id' => $opcion->id, 'nombre' => $opcion->nombre,
            'precio_extra' => (float) $opcion->precio_extra,
            'activo' => $opcion->id !== 1, 'maneja_stock' => $opcion->id === 2,
            'stock' => $opcion->id === 2 ? 5 : null,
        ])->all();
        $request = new Request([
            'nombre' => $modificador->nombre, 'tipo' => $modificador->tipo,
            'requerido' => $modificador->requerido, 'activo' => true,
            'opciones' => $opciones,
        ]);

        $response = (new ModificadorController())->update($request, $modificador);
        $this->assertSame(200, $response->getStatusCode());
        Event::assertDispatched(ProductoActualizadoEvent::class, fn ($event) => $event->producto['id'] === 9);
        Event::assertDispatched(StockActualizadoEvent::class, fn ($event) => $event->modificadorOpcionId === 2 && $event->stock === 5);
        $producto = Producto::with(['opciones.modificador', 'configuracionesModificador'])->findOrFail(9);
        $guarniciones = collect($producto->modificadores_estructurados)->firstWhere('nombre', 'Guarniciones');
        $this->assertNotContains(1, collect($guarniciones['opciones'])->pluck('id')->all());
        Event::assertDispatched(ProductoActualizadoEvent::class, function ($event) use ($modificador) {
            if ($event->producto['id'] !== 9) return false;
            $grupo = collect($event->producto['modificadores'] ?? [])->firstWhere('id', $modificador->id);
            return $grupo && !in_array(1, collect($grupo['opciones'])->pluck('id')->all());
        });

        // Volver a activar debe publicar la opción sin necesitar recargar el POS.
        Event::fake([ProductoActualizadoEvent::class, StockActualizadoEvent::class]);
        foreach ($opciones as &$opcion) $opcion['activo'] = true;
        unset($opcion);
        $request->merge(['opciones' => $opciones]);
        $this->assertSame(200, (new ModificadorController())->update($request, $modificador)->getStatusCode());
        Event::assertDispatched(ProductoActualizadoEvent::class, function ($event) use ($modificador) {
            if ($event->producto['id'] !== 9) return false;
            $grupo = collect($event->producto['modificadores'] ?? [])->firstWhere('id', $modificador->id);
            return $grupo && in_array(1, collect($grupo['opciones'])->pluck('id')->all());
        });

        Event::fake([ProductoActualizadoEvent::class, StockActualizadoEvent::class]);
        $request->merge(['activo' => false]);
        $this->assertSame(200, (new ModificadorController())->update($request, $modificador)->getStatusCode());
        Event::assertDispatched(ProductoActualizadoEvent::class, fn ($event) =>
            $event->producto['id'] === 9 && isset($event->producto['modificadores'])
            && !collect($event->producto['modificadores'])->contains('id', $modificador->id));
    }

    public function test_guarniciones_admite_hasta_el_limite_en_pollos_y_pescados(): void
    {
        $this->seed();
        $validar = new \ReflectionMethod(OrdenController::class, 'validarModificadoresProducto');

        foreach ([8 => 3, 5 => 4] as $productoId => $limite) {
            ProductoModificadorConfiguracion::create([
                'producto_id' => $productoId,
                'modificador_id' => 1,
                'cantidad_requerida' => $limite,
                // Incluso los registros antiguos sin la marca deben funcionar.
                'cantidad_es_maxima' => false,
            ]);
            $producto = Producto::with(['opciones.modificador', 'configuracionesModificador'])->findOrFail($productoId);
            $grupo = collect($producto->modificadores_estructurados)->firstWhere('nombre', 'Guarniciones');
            $this->assertTrue($grupo['cantidad_es_maxima']);

            for ($cantidad = 0; $cantidad <= $limite; $cantidad++) {
                $seleccion = $cantidad === 0 ? [] : array_map(fn ($id) => ['modificador_opcion_id' => $id], range(1, $cantidad));
                $this->assertNull($validar->invoke(new OrdenController(), $producto, $seleccion));
            }

            $exceso = array_map(fn ($id) => ['modificador_opcion_id' => $id], range(1, $limite + 1));
            try {
                $validar->invoke(new OrdenController(), $producto, $exceso);
                $this->fail('Debe rechazarse una guarnición por encima del límite.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('hasta '.$limite, $error->getMessage());
            }
        }
    }

    public function test_otros_grupos_pueden_seguir_exigiendo_cantidad_exacta(): void
    {
        $this->seed();
        ProductoModificadorConfiguracion::create([
            'producto_id' => 5,
            'modificador_id' => 2,
            'cantidad_requerida' => 1,
            'cantidad_es_maxima' => false,
        ]);
        $producto = Producto::with(['opciones.modificador', 'configuracionesModificador'])->findOrFail(5);
        $validar = new \ReflectionMethod(OrdenController::class, 'validarModificadoresProducto');
        try {
            $validar->invoke(new OrdenController(), $producto, []);
            $this->fail('El modo exacto debe seguir exigiendo una selección.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exactamente 1', $error->getMessage());
        }
    }
}
