<?php

namespace Tests\Feature;

use App\Events\ProductoActualizadoEvent;
use App\Http\Controllers\ModificadorController;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModificadorImagenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_una_imagen_por_opcion_y_la_publica_en_el_catalogo(): void
    {
        $this->seed();
        Storage::fake('public');
        Event::fake([ProductoActualizadoEvent::class]);

        $modificador = Modificador::create([
            'nombre' => 'Presas visuales', 'tipo' => 'multiple', 'requerido' => true, 'activo' => true,
        ]);
        $opcion = ModificadorOpcion::create([
            'modificador_id' => $modificador->id, 'nombre' => 'Ala', 'precio_extra' => 0,
            'activo' => true, 'maneja_stock' => false,
        ]);
        $producto = Producto::findOrFail(9);
        $producto->opciones()->attach($opcion->id, ['predeterminado' => true]);

        $request = Request::create('/modificadores/'.$modificador->id, 'POST', [
            'nombre' => $modificador->nombre, 'tipo' => $modificador->tipo,
            'color_fondo' => '#e6a817',
            'requerido' => true, 'activo' => true,
            'opciones' => [[
                'id' => $opcion->id, 'nombre' => 'Ala', 'precio_extra' => 0,
                'activo' => true, 'maneja_stock' => false, 'mostrar_imagen' => true,
            ]],
        ], [], [
            'opciones' => [['imagen' => $this->imagenFalsa('ala.png')]],
        ]);

        $response = (new ModificadorController())->update($request, $modificador);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('#e6a817', $modificador->fresh()->color_fondo);
        $opcion->refresh();
        $this->assertTrue($opcion->mostrar_imagen);
        $this->assertNotNull($opcion->imagen);
        Storage::disk('public')->assertExists($opcion->imagen);

        Event::assertDispatched(ProductoActualizadoEvent::class, function ($event) use ($producto, $opcion) {
            if ($event->producto['id'] !== $producto->id) return false;
            $publicada = collect($event->producto['modificadores'])
                ->flatMap(fn ($grupo) => $grupo['opciones'])
                ->firstWhere('id', $opcion->id);
            $grupo = collect($event->producto['modificadores'])->firstWhere('id', $opcion->modificador_id);
            return $grupo['color_fondo'] === '#e6a817' && $publicada
                && $publicada['mostrar_imagen'] === true
                && $publicada['imagen_url'] === '/storage/'.$opcion->imagen;
        });
    }

    public function test_ocultar_la_imagen_no_elimina_el_archivo(): void
    {
        Storage::fake('public');
        $ruta = 'modificadores/opciones/pecho.png';
        Storage::disk('public')->put($ruta, 'imagen-de-prueba');
        $modificador = Modificador::create([
            'nombre' => 'Presas', 'tipo' => 'unico', 'requerido' => true, 'activo' => true,
        ]);
        $opcion = ModificadorOpcion::create([
            'modificador_id' => $modificador->id, 'nombre' => 'Pecho', 'precio_extra' => 0,
            'activo' => true, 'maneja_stock' => false, 'imagen' => $ruta, 'mostrar_imagen' => true,
        ]);
        $request = new Request([
            'nombre' => $modificador->nombre, 'tipo' => $modificador->tipo,
            'requerido' => true, 'activo' => true,
            'opciones' => [[
                'id' => $opcion->id, 'nombre' => $opcion->nombre, 'precio_extra' => 0,
                'activo' => true, 'maneja_stock' => false, 'mostrar_imagen' => false,
            ]],
        ]);

        $this->assertSame(200, (new ModificadorController())->update($request, $modificador)->getStatusCode());
        $this->assertFalse($opcion->fresh()->mostrar_imagen);
        $this->assertSame($ruta, $opcion->fresh()->imagen);
        Storage::disk('public')->assertExists($ruta);
    }

    private function imagenFalsa(string $nombre): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        return UploadedFile::fake()->createWithContent($nombre, $png);
    }
}
