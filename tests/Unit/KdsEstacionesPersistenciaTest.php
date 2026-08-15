<?php

namespace Tests\Unit;

use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KdsEstacionesPersistenciaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('productos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('estacion_id')->nullable(); $table->string('nombre'); $table->timestamps();
        });
        Schema::create('modificadores', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('estacion_id')->nullable(); $table->string('nombre');
            $table->string('tipo')->default('unico'); $table->boolean('requerido')->default(false);
            $table->boolean('activo')->default(true); $table->timestamps();
        });
        Schema::create('modificador_opciones', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('modificador_id'); $table->string('nombre');
            $table->decimal('precio_extra')->default(0); $table->boolean('activo')->default(true); $table->timestamps();
        });
        Schema::create('orden_detalles', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('orden_id'); $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('estacion_id'); $table->integer('cantidad'); $table->decimal('precio_unitario');
            $table->string('estado_cocina')->default('pendiente'); $table->text('nota')->nullable();
            $table->timestamp('fecha_servido')->nullable(); $table->timestamps();
        });
        Schema::create('orden_detalle_opciones', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('orden_detalle_id'); $table->unsignedBigInteger('modificador_opcion_id');
            $table->decimal('precio_extra')->default(0); $table->timestamps();
        });
        Schema::create('orden_detalle_estaciones', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('orden_detalle_id'); $table->unsignedBigInteger('estacion_id');
            $table->string('estado')->default('pendiente'); $table->timestamp('fecha_servido')->nullable(); $table->timestamps();
            $table->unique(['orden_detalle_id', 'estacion_id']);
        });
    }

    protected function tearDown(): void
    {
        foreach (['orden_detalle_estaciones', 'orden_detalle_opciones', 'orden_detalles', 'modificador_opciones', 'modificadores', 'productos'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_dos_productos_con_modificadores_conservan_opciones_y_generan_trabajo_por_estacion(): void
    {
        $producto1 = \App\Models\Producto::create(['nombre' => 'Pescado', 'estacion_id' => 2]);
        $producto2 = \App\Models\Producto::create(['nombre' => 'Sopa', 'estacion_id' => 1]);
        $modCocina = Modificador::create(['nombre' => 'Guarnición', 'estacion_id' => 1, 'tipo' => 'multiple', 'requerido' => false, 'activo' => true]);
        $modParrilla = Modificador::create(['nombre' => 'Término', 'estacion_id' => 2, 'tipo' => 'unico', 'requerido' => true, 'activo' => true]);
        $mote = ModificadorOpcion::create(['modificador_id' => $modCocina->id, 'nombre' => 'Mote', 'precio_extra' => 0, 'activo' => true]);
        $medio = ModificadorOpcion::create(['modificador_id' => $modParrilla->id, 'nombre' => 'Medio', 'precio_extra' => 0, 'activo' => true]);

        $detalle1 = OrdenDetalle::create(['orden_id' => 1, 'producto_id' => $producto1->id, 'estacion_id' => 2, 'cantidad' => 1, 'precio_unitario' => 50, 'estado_cocina' => 'pendiente']);
        $detalle2 = OrdenDetalle::create(['orden_id' => 1, 'producto_id' => $producto2->id, 'estacion_id' => 1, 'cantidad' => 2, 'precio_unitario' => 20, 'estado_cocina' => 'pendiente']);
        OrdenDetalleOpcion::create(['orden_detalle_id' => $detalle1->id, 'modificador_opcion_id' => $mote->id, 'precio_extra' => 0]);
        OrdenDetalleOpcion::create(['orden_detalle_id' => $detalle1->id, 'modificador_opcion_id' => $medio->id, 'precio_extra' => 0]);

        $this->assertDatabaseCount('orden_detalle_opciones', 2);
        $this->assertDatabaseHas('orden_detalle_estaciones', ['orden_detalle_id' => $detalle1->id, 'estacion_id' => 1]);
        $this->assertDatabaseHas('orden_detalle_estaciones', ['orden_detalle_id' => $detalle1->id, 'estacion_id' => 2]);
        $this->assertDatabaseHas('orden_detalle_estaciones', ['orden_detalle_id' => $detalle2->id, 'estacion_id' => 1]);
    }
}
