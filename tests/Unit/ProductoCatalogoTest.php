<?php

namespace Tests\Unit;

use App\Events\ProductoActualizadoEvent;
use App\Http\Controllers\OrdenController;
use App\Models\Producto;
use Tests\TestCase;

class ProductoCatalogoTest extends TestCase
{
    public function test_rechaza_precios_antiguos_tanto_al_subir_como_al_bajar(): void
    {
        $validar = new \ReflectionMethod(OrdenController::class, 'validarPrecioDisponible');
        foreach ([8, 15] as $precio) {
            $producto = new Producto(['nombre' => 'Refresco', 'precio' => $precio, 'activo' => true]);
            try {
                $validar->invoke(new OrdenController(), $producto, ['precio_unitario' => 10]);
                $this->fail('Debe rechazar el precio anterior.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('cambió', $error->getMessage());
            }
            $this->assertNull($validar->invoke(new OrdenController(), $producto, ['precio_unitario' => $precio]));
        }
    }

    public function test_rechaza_nuevas_unidades_de_producto_inactivo(): void
    {
        $validar = new \ReflectionMethod(OrdenController::class, 'validarPrecioDisponible');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('desactivado');
        $validar->invoke(new OrdenController(), new Producto(['nombre' => 'Refresco', 'precio' => 10, 'activo' => false]), ['precio_unitario' => 10]);
    }

    public function test_evento_publica_el_catalogo_sin_cambiar_pedidos(): void
    {
        $producto = ['id' => 1, 'precio' => '15.00', 'activo' => false];
        $evento = new ProductoActualizadoEvent($producto);
        $this->assertSame('ProductoActualizado', $evento->broadcastAs());
        $this->assertSame('canal-inventario', $evento->broadcastOn()[0]->name);
        $this->assertSame(['producto' => $producto], $evento->broadcastWith());
    }
}
