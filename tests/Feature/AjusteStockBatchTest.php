<?php

namespace Tests\Feature;

use App\Events\StockActualizadoEvent;
use App\Models\AjusteStock;
use App\Models\Categoria;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AjusteStockBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_reabastece_varias_opciones_del_producto_en_una_sola_operacion(): void
    {
        Event::fake([StockActualizadoEvent::class]);
        $admin = User::factory()->create(['role_id' => Role::create(['nombre' => 'Administrador'])->id]);
        $category = Categoria::create(['nombre' => 'Pollos']);
        $product = Producto::create([
            'categoria_id' => $category->id,
            'nombre' => 'Pollo doble presa',
            'precio' => 30,
            'activo' => true,
            'maneja_stock' => false,
        ]);
        $modifier = Modificador::create(['nombre' => 'Presa', 'tipo' => 'unico', 'requerido' => true, 'activo' => true]);
        $wing = ModificadorOpcion::create(['modificador_id' => $modifier->id, 'nombre' => 'Ala', 'precio_extra' => 0, 'activo' => true, 'maneja_stock' => true, 'stock' => 1, 'stock_minimo' => 1]);
        $leg = ModificadorOpcion::create(['modificador_id' => $modifier->id, 'nombre' => 'Pierna', 'precio_extra' => 0, 'activo' => true, 'maneja_stock' => true, 'stock' => 2, 'stock_minimo' => 1]);
        $product->opciones()->attach([$wing->id, $leg->id]);

        $this->withToken(JWTAuth::fromUser($admin))->postJson('/api/ajustes-stock/lote', [
            'producto_id' => $product->id,
            'motivo' => 'Reabastecimiento de prueba',
            'items' => [
                ['modificador_opcion_id' => $wing->id, 'cantidad' => 4],
                ['modificador_opcion_id' => $leg->id, 'cantidad' => 3],
            ],
        ])->assertCreated()->assertJsonCount(2, 'ajustes');

        $this->assertSame(5, $wing->fresh()->stock);
        $this->assertSame(5, $leg->fresh()->stock);
        $this->assertSame(2, AjusteStock::where('motivo', 'Reabastecimiento de prueba')->count());
        Event::assertDispatchedTimes(StockActualizadoEvent::class, 2);
    }
}
