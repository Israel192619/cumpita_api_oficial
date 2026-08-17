<?php

namespace Tests\Unit;

use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\Orden;
use App\Http\Controllers\CocinaController;
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

    public function test_cocina_desbloquea_solo_la_guarnicion_del_pescado_terminado(): void
    {
        $pescado = \App\Models\Producto::create(['nombre' => 'Pescado', 'estacion_id' => 2]);
        $guarnicion = Modificador::create([
            'nombre' => 'Guarnición', 'estacion_id' => 1, 'tipo' => 'unico',
            'requerido' => true, 'activo' => true,
        ]);
        $opciones = collect(['Mote', 'Papa', 'Ensalada'])->map(fn ($nombre) => ModificadorOpcion::create([
            'modificador_id' => $guarnicion->id, 'nombre' => $nombre,
            'precio_extra' => 0, 'activo' => true,
        ]));

        $detalles = $opciones->map(function ($opcion) use ($pescado) {
            $detalle = OrdenDetalle::create([
                'orden_id' => 125, 'producto_id' => $pescado->id, 'estacion_id' => 2,
                'cantidad' => 1, 'precio_unitario' => 60, 'estado_cocina' => 'pendiente',
            ]);
            OrdenDetalleOpcion::create([
                'orden_detalle_id' => $detalle->id,
                'modificador_opcion_id' => $opcion->id,
                'precio_extra' => 0,
            ]);
            return $detalle;
        });

        $detalles->first()->estadosEstacion()->where('estacion_id', 2)->update(['estado' => 'servido']);
        $detalles = OrdenDetalle::with([
            'producto', 'estadosEstacion', 'opciones.modificadorOpcion.modificador',
        ])->whereIn('id', $detalles->pluck('id'))->get();
        $detalles->each(fn ($detalle) => $detalle->producto->setAppends([]));
        $orden = new Orden(['numero_orden' => 125, 'estado' => 'preparando']);
        $orden->id = 125;
        $orden->setAppends([]);
        $orden->setRelation('detalles', $detalles);

        $metodo = new \ReflectionMethod(CocinaController::class, 'proyectarOrden');
        $resultado = $metodo->invoke(new CocinaController(), $orden, 1, ['pendiente', 'en_preparacion', 'listo_para_recoger']);

        $this->assertCount(3, $resultado['detalles']);
        $this->assertSame($detalles->first()->id, $resultado['detalles'][0]['id']);
        $this->assertFalse($resultado['detalles'][0]['bloqueado']);
        $this->assertTrue($resultado['detalles'][0]['listo_para_atender']);
        $this->assertSame('Mote', $resultado['detalles'][0]['opciones'][0]['modificador_opcion']['nombre']);
        $this->assertTrue($resultado['detalles'][1]['bloqueado']);
        $this->assertFalse($resultado['detalles'][1]['listo_para_atender']);
        $this->assertSame('Papa', $resultado['detalles'][1]['opciones'][0]['modificador_opcion']['nombre']);
        $this->assertTrue($resultado['detalles'][2]['bloqueado']);
        $this->assertSame('Ensalada', $resultado['detalles'][2]['opciones'][0]['modificador_opcion']['nombre']);
    }
}
