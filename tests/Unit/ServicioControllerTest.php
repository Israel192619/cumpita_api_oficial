<?php

namespace Tests\Unit;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\ServicioSesionActualizadaEvent;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\UserController;
use App\Models\Modificador;
use App\Models\ModificadorOpcion;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use App\Services\KdsEstacionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ServicioControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('nombre'); $table->text('descripcion')->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('role_id')->nullable(); $table->unsignedBigInteger('estacion_id')->nullable(); $table->string('name');
            $table->string('email')->unique(); $table->string('password'); $table->string('pin')->nullable();
            $table->rememberToken(); $table->timestamps();
        });
        Schema::create('estaciones_trabajo', function (Blueprint $table) {
            $table->id(); $table->string('nombre'); $table->string('codigo');
            $table->boolean('activa')->default(true); $table->integer('orden')->default(1);
        });
        \DB::table('estaciones_trabajo')->insert([
            'id' => 4, 'nombre' => 'Meseros', 'codigo' => 'MESEROS', 'activa' => true, 'orden' => 4,
        ]);
        Schema::create('perfil_usuarios', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('direccion')->nullable();
            $table->string('numero_celular')->nullable(); $table->string('avatar')->nullable(); $table->timestamps();
        });
        Schema::create('ordenes', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->unsignedBigInteger('mesero_id')->nullable();
            $table->unsignedInteger('numero_orden'); $table->string('estado')->default('pendiente');
            $table->string('tipo_flujo')->default('normal'); $table->string('estado_preorden')->nullable();
            $table->string('estado_solicitud')->nullable();
            $table->timestamp('fecha_orden')->nullable(); $table->timestamp('fecha_programada')->nullable();
            $table->timestamp('preorden_activada_en')->nullable();
            $table->timestamp('tomada_en')->nullable(); $table->timestamp('entregada_en')->nullable(); $table->timestamps();
        });
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
            $table->unsignedBigInteger('estacion_id'); $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario')->default(0); $table->string('estado_cocina')->default('pendiente');
            $table->text('nota')->nullable(); $table->timestamp('fecha_servido')->nullable(); $table->timestamps();
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
        Schema::create('historial_cambios_orden', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('orden_id'); $table->unsignedBigInteger('orden_detalle_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); $table->unsignedBigInteger('producto_id')->nullable();
            $table->string('tipo_cambio'); $table->integer('cantidad_anterior')->nullable();
            $table->integer('cantidad_nueva')->nullable(); $table->json('datos_anterior')->nullable();
            $table->json('datos_nuevo')->nullable(); $table->timestamps();
        });

        Event::fake([OrdenCocinaActualizadaEvent::class, ServicioSesionActualizadaEvent::class]);
    }

    protected function tearDown(): void
    {
        foreach (['historial_cambios_orden', 'orden_detalle_estaciones', 'orden_detalle_opciones', 'orden_detalles', 'modificador_opciones',
            'modificadores', 'productos', 'ordenes', 'perfil_usuarios', 'users', 'estaciones_trabajo', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_dos_meseros_no_pueden_tomar_la_misma_ficha(): void
    {
        [$meseroA, $meseroB] = $this->meseros();
        $orden = Orden::create(['user_id' => $meseroA->id, 'numero_orden' => 125, 'estado' => 'pendiente']);
        $controller = new ServicioController();

        $this->autenticarServicio($meseroA);
        $this->assertSame(200, $controller->tomar($orden)->status());
        Event::assertDispatched(OrdenCocinaActualizadaEvent::class, fn ($evento) => $evento->ordenId === $orden->id);
        $this->autenticarServicio($meseroB);

        try {
            $controller->tomar($orden->fresh());
            $this->fail('La segunda toma debía ser rechazada.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame($meseroA->id, $orden->fresh()->mesero_id);
    }

    public function test_mesero_con_jwt_principal_entra_y_trabaja_sin_pin(): void
    {
        [$mesero] = $this->meseros();
        $orden = Orden::create(['user_id' => $mesero->id, 'numero_orden' => 140, 'estado' => 'pendiente']);
        $token = JWTAuth::fromUser($mesero);
        JWTAuth::setToken($token)->authenticate();
        auth('api')->setUser($mesero);

        $controller = new ServicioController();
        $this->assertSame(200, $controller->tomar($orden)->status());
        $tablero = $controller->index(Request::create('/', 'GET'), app(KdsEstacionService::class))->getData(true);

        $this->assertCount(1, $tablero['mis_fichas']);
        $this->assertSame($mesero->id, $orden->fresh()->mesero_id);
    }

    public function test_despacho_puede_ver_servicio_pero_no_actuar_como_mesero(): void
    {
        $role = Role::create(['nombre' => 'Despacho']);
        $despacho = User::create([
            'name' => 'Despacho', 'email' => 'despacho@test.local',
            'password' => Hash::make('password'), 'role_id' => $role->id,
        ]);
        $orden = Orden::create(['user_id' => $despacho->id, 'numero_orden' => 141, 'estado' => 'pendiente']);
        $token = JWTAuth::fromUser($despacho);
        JWTAuth::setToken($token)->authenticate();
        auth('api')->setUser($despacho);
        $controller = new ServicioController();

        $this->assertSame(200, $controller->index(Request::create('/', 'GET'), app(KdsEstacionService::class))->status());
        try {
            $controller->tomar($orden);
            $this->fail('Despacho no debe actuar como mesero.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_preorden_programada_no_aparece_en_servicio_hasta_ser_activada(): void
    {
        [$mesero] = $this->meseros();
        $programada = Orden::create([
            'user_id' => $mesero->id, 'numero_orden' => 142, 'estado' => 'pendiente',
            'tipo_flujo' => 'preorden', 'estado_preorden' => 'programada', 'fecha_programada' => now(),
        ]);
        $activada = Orden::create([
            'user_id' => $mesero->id, 'numero_orden' => 143, 'estado' => 'pendiente',
            'tipo_flujo' => 'preorden', 'estado_preorden' => 'activada',
            'fecha_orden' => now(), 'preorden_activada_en' => now(),
        ]);
        $token = JWTAuth::fromUser($mesero);
        JWTAuth::setToken($token)->authenticate();
        auth('api')->setUser($mesero);

        $tablero = (new ServicioController())->index(Request::create('/', 'GET'), app(KdsEstacionService::class))->getData(true);

        $this->assertSame([$activada->id], collect($tablero['disponibles'])->pluck('id')->all());
        $this->assertNotContains($programada->id, collect($tablero['disponibles'])->pluck('id')->all());
        $this->assertSame([$programada->id], collect($tablero['preordenes_programadas'])->pluck('id')->all());
        $this->assertTrue($tablero['preordenes_programadas'][0]['bloqueada']);

        try {
            (new ServicioController())->tomar($programada);
            $this->fail('Una preorden programada no debe poder tomarse antes de activarla.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_cerrar_servicio_desde_celular_no_invalida_el_jwt_principal(): void
    {
        [$mesero] = $this->meseros();
        $token = JWTAuth::fromUser($mesero);
        JWTAuth::setToken($token)->authenticate();
        auth('api')->setUser($mesero);

        $response = (new ServicioController())->cerrarSesion(
            Request::create('/api/servicio/sesion/cerrar', 'POST')
        );

        $this->assertSame(200, $response->status());
        $this->assertSame($mesero->id, JWTAuth::setToken($token)->authenticate()->id);
    }

    public function test_confirmacion_manual_habilita_entrega_y_registra_hora(): void
    {
        [$mesero] = $this->meseros();
        $orden = Orden::create([
            'user_id' => $mesero->id, 'mesero_id' => $mesero->id,
            'numero_orden' => 126, 'estado' => 'preparando', 'tomada_en' => now(),
        ]);
        $producto = Producto::create(['nombre' => 'Pescado', 'estacion_id' => 2]);
        $modificador = Modificador::create([
            'nombre' => 'Guarnición', 'estacion_id' => 1, 'tipo' => 'unico', 'requerido' => true, 'activo' => true,
        ]);
        $opcion = ModificadorOpcion::create([
            'modificador_id' => $modificador->id, 'nombre' => 'Mote', 'precio_extra' => 0, 'activo' => true,
        ]);
        $detalle = OrdenDetalle::create([
            'orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 2,
            'cantidad' => 1, 'precio_unitario' => 60, 'estado_cocina' => 'pendiente',
        ]);
        OrdenDetalleOpcion::create([
            'orden_detalle_id' => $detalle->id, 'modificador_opcion_id' => $opcion->id, 'precio_extra' => 0,
        ]);
        $controller = new ServicioController();
        $this->autenticarServicio($mesero);

        try {
            $controller->entregar($orden);
            $this->fail('No se debe entregar mientras haya estaciones pendientes.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $controller->confirmarDetalle($detalle, app(KdsEstacionService::class));
        Event::assertDispatched(OrdenCocinaActualizadaEvent::class, fn ($evento) => $evento->ordenId === $orden->id);
        $this->assertDatabaseMissing('orden_detalle_estaciones', [
            'orden_detalle_id' => $detalle->id, 'estado' => 'pendiente',
        ]);
        $this->assertSame(200, $controller->entregar($orden->fresh())->status());
        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertNotNull($orden->fresh()->entregada_en);
    }

    public function test_pin_de_mesero_se_valida_con_hash(): void
    {
        [$mesero] = $this->meseros();
        $mesero->update(['pin' => Hash::make('2468')]);

        $response = (new AuthController())->loginPin(Request::create('/api/acceso-rapido/pin', 'POST', [
            'user_id' => $mesero->id, 'pin' => '2468',
        ]));

        $this->assertSame(200, $response->status());
        $this->assertNotEmpty($response->getData(true)['token'] ?? null);
        $payload = JWTAuth::setToken($response->getData(true)['token'])->getPayload();
        $this->assertSame('servicio', $payload->get('scope'));
        $this->assertSame($response->getData(true)['session_id'], $payload->get('session_id'));
        $this->assertNotSame('2468', $mesero->fresh()->pin);
    }

    public function test_creacion_y_restablecimiento_de_pin_nunca_lo_exponen(): void
    {
        $role = Role::create(['nombre' => 'Mesero']);
        $controller = new UserController();
        $creacion = $controller->store(new Request([
            'name' => 'Ana', 'email' => 'ana@test.local', 'password' => 'password123',
            'pin' => '1234', 'role_id' => $role->id, 'direccion' => 'Centro', 'numero_celular' => '70000000',
        ]));
        $user = User::where('email', 'ana@test.local')->firstOrFail();

        $this->assertSame(201, $creacion->status());
        $this->assertTrue(Hash::check('1234', $user->pin));
        $this->assertArrayNotHasKey('pin', $creacion->getData(true)['user']);

        $actualizacion = $controller->update(new Request([
            'name' => 'Ana', 'email' => 'ana@test.local', 'pin' => '5678',
            'role_id' => $role->id, 'direccion' => 'Centro', 'numero_celular' => '70000000',
        ]), (string) $user->id);

        $this->assertSame(200, $actualizacion->status());
        $this->assertTrue(Hash::check('5678', $user->fresh()->pin));
        $this->assertArrayNotHasKey('pin', $actualizacion->getData(true)['data']);
    }

    public function test_liberar_ficha_conserva_estado_y_registra_historial(): void
    {
        [$mesero] = $this->meseros();
        $orden = Orden::create([
            'user_id' => $mesero->id, 'mesero_id' => $mesero->id, 'numero_orden' => 130,
            'estado' => 'preparando', 'tomada_en' => now(),
        ]);
        $this->autenticarServicio($mesero);

        $response = (new ServicioController())->liberar($orden);

        $this->assertSame(200, $response->status());
        $this->assertNull($orden->fresh()->mesero_id);
        $this->assertSame('preparando', $orden->fresh()->estado);
        $this->assertDatabaseHas('historial_cambios_orden', [
            'orden_id' => $orden->id, 'user_id' => $mesero->id, 'tipo_cambio' => 'estado_cambiado',
        ]);
    }

    public function test_cierre_individual_libera_solo_las_fichas_del_mesero_y_conserva_historial(): void
    {
        [$juan, $pedro] = $this->meseros();
        $juan->update(['name' => 'Juan', 'pin' => Hash::make('1111')]);
        $pedro->update(['name' => 'Pedro', 'pin' => Hash::make('2222')]);
        $authController = new AuthController();
        $sesionJuan = $authController->loginPin(Request::create('/api/acceso-rapido/pin', 'POST', [
            'user_id' => $juan->id, 'pin' => '1111',
        ]))->getData(true);
        $sesionPedro = $authController->loginPin(Request::create('/api/acceso-rapido/pin', 'POST', [
            'user_id' => $pedro->id, 'pin' => '2222',
        ]))->getData(true);

        $this->assertNotSame($sesionJuan['session_id'], $sesionPedro['session_id']);
        $this->assertSame($juan->id, JWTAuth::setToken($sesionJuan['token'])->authenticate()->id);
        $this->assertSame($pedro->id, JWTAuth::setToken($sesionPedro['token'])->authenticate()->id);

        $ficha125 = Orden::create(['user_id' => $juan->id, 'numero_orden' => 125, 'estado' => 'pendiente']);
        $ficha126 = Orden::create(['user_id' => $juan->id, 'numero_orden' => 126, 'estado' => 'pendiente']);
        $fichaPedro = Orden::create(['user_id' => $pedro->id, 'mesero_id' => $pedro->id, 'numero_orden' => 127, 'estado' => 'pendiente', 'tomada_en' => now()]);
        JWTAuth::setToken($sesionJuan['token'])->authenticate();
        auth('api')->setUser($juan);
        $servicioController = new ServicioController();
        $servicioController->tomar($ficha125);
        $servicioController->tomar($ficha126);

        JWTAuth::setToken($sesionJuan['token'])->authenticate();
        auth('api')->setUser($juan);
        $servicioController->cerrarSesion(Request::create('/api/servicio/sesion/cerrar', 'POST', ['liberar_fichas' => true]));

        $this->assertNull($ficha125->fresh()->mesero_id);
        $this->assertNull($ficha126->fresh()->mesero_id);
        $this->assertSame($pedro->id, $fichaPedro->fresh()->mesero_id);
        $this->assertDatabaseCount('historial_cambios_orden', 2);
        $this->assertSame($pedro->id, JWTAuth::setToken($sesionPedro['token'])->authenticate()->id);

        $nuevaSesionJuan = $authController->loginPin(Request::create('/api/acceso-rapido/pin', 'POST', [
            'user_id' => $juan->id, 'pin' => '1111',
        ]))->getData(true);
        $this->assertNotSame($sesionJuan['session_id'], $nuevaSesionJuan['session_id']);
        $this->assertSame($juan->id, JWTAuth::setToken($nuevaSesionJuan['token'])->authenticate()->id);
    }

    public function test_colaboracion_conserva_responsable_y_evita_doble_entrega(): void
    {
        [$responsable, $ayudante] = $this->meseros();
        $orden = Orden::create(['user_id' => $responsable->id, 'mesero_id' => $responsable->id, 'numero_orden' => 900, 'estado' => 'listo']);
        $producto = Producto::create(['nombre' => 'Sopa', 'estacion_id' => 1]);
        $detalle = OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 1, 'estado_cocina' => 'listo_para_recoger']);
        $controller = new ServicioController();
        $kds = app(KdsEstacionService::class);
        $request = fn ($accion) => Request::create('/', 'POST', ['accion' => $accion]);
        $this->autenticarServicio($ayudante);
        $controller->colaborar($request('llevar'), $detalle, $kds);
        $this->assertSame($responsable->id, $orden->fresh()->mesero_id);
        $estado = app(\App\Services\ServicioColaboracionService::class)->estado($detalle->fresh());
        $this->assertSame($ayudante->id, $estado['llevando_por_id']);
        $this->autenticarServicio($responsable);
        foreach (['llevar', 'entregar'] as $accion) {
            try {
                $controller->colaborar($request($accion), $detalle, $kds);
                $this->fail('No debe permitir tomar ni entregar lo que lleva otro mesero.');
            } catch (HttpExceptionInterface $error) {
                $this->assertSame($accion === 'llevar' ? 409 : 403, $error->getStatusCode());
            }
        }
        try {
            $controller->entregar($orden);
            $this->fail('No debe cerrar mientras hay productos en camino.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->autenticarServicio($ayudante);
        $controller->colaborar($request('entregar'), $detalle, $kds);
        $estado = app(\App\Services\ServicioColaboracionService::class)->estado($detalle->fresh());
        $this->assertNull($estado['llevando_por_id']);
        $this->assertSame($ayudante->name, $estado['entregado_por']);
        $this->assertTrue($estado['servido']);
        $this->assertSame('listo', $orden->fresh()->estado);
        try {
            $controller->entregar($orden);
            $this->fail('El ayudante no debe cerrar la ficha.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->autenticarServicio($responsable);
        $controller->entregar($orden);
        $this->assertSame('entregado', $orden->fresh()->estado);
    }

    public function test_colaboracion_bloquea_pendientes_y_permite_cancelar_traslado(): void
    {
        [$responsable, $ayudante] = $this->meseros();
        $orden = Orden::create(['user_id' => $responsable->id, 'mesero_id' => $responsable->id, 'numero_orden' => 901, 'estado' => 'preparando']);
        $producto = Producto::create(['nombre' => 'Sopa', 'estacion_id' => 1]);
        $detalle = OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 1]);
        $controller = new ServicioController();
        $kds = app(KdsEstacionService::class);
        $request = fn ($accion) => Request::create('/', 'POST', ['accion' => $accion]);
        $this->autenticarServicio($ayudante);
        try {
            $controller->colaborar($request('llevar'), $detalle, $kds);
            $this->fail('No se puede llevar un producto pendiente.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $detalle->estadosEstacion()->update(['estado' => 'listo_para_recoger']);
        $controller->colaborar($request('llevar'), $detalle, $kds);
        $this->autenticarServicio($responsable);
        $controller->colaborar($request('cancelar'), $detalle, $kds);
        $controller->colaborar($request('llevar'), $detalle, $kds);
        $this->assertSame($responsable->id, app(\App\Services\ServicioColaboracionService::class)->estado($detalle->fresh())['llevando_por_id']);
        $this->assertDatabaseCount('historial_cambios_orden', 3);
        $orden->update(['estado' => 'cancelado']);
        try {
            $controller->colaborar($request('entregar'), $detalle, $kds);
            $this->fail('No se puede colaborar en una ficha cancelada.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    public function test_colaboracion_tablero_incluye_fichas_ajenas_y_categoria_padre(): void
    {
        Schema::table('productos', fn (Blueprint $table) => $table->unsignedBigInteger('categoria_id')->nullable());
        Schema::create('categorias', function (Blueprint $table) {
            $table->id(); $table->string('nombre'); $table->unsignedBigInteger('parent_id')->nullable(); $table->timestamps();
        });
        try {
            [$responsable, $ayudante] = $this->meseros();
            $padre = \App\Models\Categoria::create(['nombre' => 'Comidas']);
            $hija = \App\Models\Categoria::create(['nombre' => 'Sopas', 'parent_id' => $padre->id]);
            $producto = Producto::create(['nombre' => 'Sopa', 'estacion_id' => 1, 'categoria_id' => $hija->id]);
            $orden = Orden::create(['user_id' => $responsable->id, 'mesero_id' => $responsable->id, 'numero_orden' => 902, 'estado' => 'listo']);
            OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 1, 'estado_cocina' => 'listo_para_recoger']);
            $entregada = Orden::create(['user_id' => $responsable->id, 'mesero_id' => $responsable->id, 'numero_orden' => 903, 'estado' => 'entregado']);
            $this->autenticarServicio($ayudante);
            $tablero = (new ServicioController())->index(Request::create('/'), app(KdsEstacionService::class))->getData(true);
            $this->assertCount(2, $tablero['todas_fichas']);
            $this->assertCount(0, $tablero['mis_fichas']);
            $this->assertCount(0, $tablero['mis_entregadas']);
            $this->assertSame($responsable->id, $tablero['todas_fichas'][0]['mesero_id']);
            $this->assertSame('Comidas', $tablero['todas_fichas'][0]['detalles'][0]['categoria']);
            $this->assertSame('entregado', $tablero['todas_fichas'][1]['estado']);
        } finally {
            Schema::dropIfExists('categorias');
        }
    }

    public function test_check_compartido_sin_asignar_y_asignado_conserva_autoria(): void
    {
        [$ana, $juan] = $this->meseros();
        $orden = Orden::create(['user_id' => $ana->id, 'numero_orden' => 904, 'estado' => 'pendiente']);
        $producto = Producto::create(['nombre' => 'Refresco', 'estacion_id' => 1]);
        $crear = fn () => OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 1]);
        $refresco = $crear();
        $otro = $crear();
        $controller = new ServicioController();
        $kds = app(KdsEstacionService::class);
        $colaboracion = app(\App\Services\ServicioColaboracionService::class);
        $this->autenticarServicio($ana);
        $controller->confirmarDetalle($refresco, $kds);
        $this->assertNull($orden->fresh()->mesero_id);
        $this->assertSame($ana->name, $colaboracion->estado($refresco->fresh())['entregado_por']);
        $this->autenticarServicio($juan);
        $controller->tomar($orden);
        try {
            $controller->confirmarDetalle($refresco, $kds);
            $this->fail('Un segundo check no debe cambiar quién lo entregó.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->autenticarServicio($ana);
        $controller->confirmarDetalle($otro, $kds);
        $this->assertSame($juan->id, $orden->fresh()->mesero_id);
        try {
            $controller->entregar($orden);
            $this->fail('Quien ayuda no debe cerrar la ficha.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->autenticarServicio($juan);
        $controller->liberar($orden);
        $this->autenticarServicio($ana);
        $controller->tomar($orden);
        $controller->entregar($orden);
        $this->assertSame('entregado', $orden->fresh()->estado);
        $this->assertSame($ana->name, $colaboracion->estado($refresco->fresh())['entregado_por']);
        $this->assertSame($ana->name, $colaboracion->estado($otro->fresh())['entregado_por']);
    }

    public function test_edicion_conserva_precio_y_estacion_de_producto_vendido_desactivado(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('precio')->default(0); $table->boolean('activo')->default(true);
        });
        Schema::create('producto_opciones', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id'); $table->unsignedBigInteger('modificador_opcion_id');
            $table->boolean('predeterminado')->default(false);
        });
        Schema::create('producto_modificador_configuraciones', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('producto_id'); $table->unsignedBigInteger('modificador_id');
            $table->integer('cantidad_requerida');
        });
        try {
            [$mesero] = $this->meseros();
            $orden = Orden::create(['user_id' => $mesero->id, 'numero_orden' => 905, 'estado' => 'listo']);
            $producto = Producto::create(['nombre' => 'Refresco', 'estacion_id' => 1, 'precio' => 10, 'activo' => true]);
            $detalle = OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => 1,
                'precio_unitario' => 10, 'estado_cocina' => 'servido']);
            $producto->update(['precio' => 15, 'activo' => false, 'estacion_id' => 2]);
            $items = [['orden_detalle_id' => $detalle->id, 'producto_id' => $producto->id, 'cantidad' => 1,
                'precio_unitario' => 10, 'modificadores' => []]];
            $historial = [];
            $metodo = new \ReflectionMethod(\App\Http\Controllers\OrdenController::class, 'sincronizarDetallesOrden');
            $this->assertFalse($metodo->invokeArgs(new \App\Http\Controllers\OrdenController(), [$orden, $items, $mesero->id, &$historial]));
            $this->assertSame('10.00', $detalle->fresh()->precio_unitario);
            $this->assertSame(1, $detalle->fresh()->estacion_id);
            $this->assertSame('servido', $detalle->fresh()->estado_cocina);
            $this->assertDatabaseCount('orden_detalles', 1);
        } finally {
            Schema::dropIfExists('producto_modificador_configuraciones');
            Schema::dropIfExists('producto_opciones');
        }
    }

    private function autenticarServicio(User $mesero): void
    {
        $token = JWTAuth::claims([
            'scope' => 'servicio', 'session_id' => 'test-' . $mesero->id,
        ])->fromUser($mesero);
        JWTAuth::setToken($token)->authenticate();
        auth('api')->setUser($mesero);
    }

    private function meseros(): array
    {
        $role = Role::firstOrCreate(['nombre' => 'Mesero']);
        return collect(['a', 'b'])->map(fn ($sufijo) => User::create([
            'name' => 'Mesero ' . strtoupper($sufijo), 'email' => $sufijo . '@test.local',
            'password' => Hash::make('password'), 'role_id' => $role->id,
        ]))->all();
    }
}
