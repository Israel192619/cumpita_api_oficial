<?php

namespace App\Http\Controllers;

use App\Events\OrdenCreadaEvent;
use App\Jobs\SendGrillOrderPush;
use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\PreordenActualizadaEvent;
use App\Events\CajaActualizadaEvent;
use App\Events\StockActualizadoEvent;
use App\Events\ServicioFichaActualizadaEvent;
use App\Models\HistorialCambioOrden;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\PagoOrden;
use App\Models\Cliente;
use App\Models\Caja;
use App\Models\Producto;
use App\Models\ProductoCombinacion;
use App\Models\ModificadorOpcion;
use App\Models\ReservaStock;
use App\Models\ReservaStockModificador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class OrdenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, ?\App\Services\PreordenActivationService $activacionPreorden = null)
    {
        ($activacionPreorden ?? app(\App\Services\PreordenActivationService::class))->activarDeliveriesProximos();
        $query = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto.combinaciones.opciones', 'detalles.estacion', 'detalles.opciones.modificadorOpcion', 'preordenActivadaPor')
            ->where(fn ($query) => $query->whereNull('estado_solicitud')->orWhereIn('estado_solicitud', ['aceptada', 'rechazada']))
            ->withMax('cambiosMesero as ultimo_cambio_mesero_en', 'created_at')
            ->when($request->filled('tipo_flujo'), fn ($query) => $query->where('tipo_flujo', $request->input('tipo_flujo')))
            ->when($request->filled('estado_preorden'), fn ($query) => $query->where('estado_preorden', $request->input('estado_preorden')));

        if ($request->boolean('paginated')) {
            $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:5|max:100',
                'search' => 'nullable|string|max:120',
                'sort_direction' => 'nullable|in:asc,desc',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
            ]);
            $query->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->input('search'));
                $query->where(function ($query) use ($search) {
                    $query->where('id', $search)
                        ->orWhere('numero_orden', 'like', "%{$search}%")
                        ->orWhere('observaciones', 'like', "%{$search}%")
                        ->orWhereHas('cliente', fn ($clientQuery) => $clientQuery->where('nombre', 'like', "%{$search}%"));
                });
            });
            $query->when($request->filled('date_from'), fn ($query) => $query->whereRaw('COALESCE(fecha_programada, fecha_orden, created_at) >= ?', [$request->input('date_from')]))
                ->when($request->filled('date_to'), function ($query) use ($request) {
                    $to = $request->input('date_to');
                    if (!str_contains($to, 'T') && !str_contains($to, ' ')) $to .= ' 23:59:59';
                    $query->whereRaw('COALESCE(fecha_programada, fecha_orden, created_at) <= ?', [$to]);
                });
            $sortColumns = ['id', 'numero_orden', 'tipo_orden', 'tipo_flujo', 'fecha_programada', 'total', 'estado', 'created_at'];
            $sort = in_array($request->input('sort_key'), $sortColumns, true) ? $request->input('sort_key') : 'created_at';
            $direction = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sort, $direction);
            if ($sort !== 'id') {
                $query->orderBy('id', $direction);
            }
            return response()->json(['ordenes' => $query->paginate((int) $request->input('per_page', 10))]);
        }

        $ordenes = $query->orderByDesc('created_at')->get();
        return response()->json([
            'ordenes' => $ordenes
        ], 200);
    }

    public function prepararCambioDelivery(Request $request, Orden $orden)
    {
        $data = $request->validate([
            'preparado' => ['required', 'boolean'],
            'monto_esperado' => ['nullable', 'numeric', 'min:0'],
        ]);

        $orden = DB::transaction(function () use ($orden, $data) {
            $orden = Orden::with('pagos')->lockForUpdate()->findOrFail($orden->id);
            abort_unless($orden->tipo_orden === 'delivery', 422, 'Esta acción solo está disponible para pedidos delivery.');
            abort_if($orden->estado === 'cancelado', 422, 'No se puede preparar cambio para una orden cancelada.');

            $saldo = round((float) $orden->saldo_pendiente, 2);
            $preparado = (bool) $data['preparado'];
            $montoEsperado = $preparado ? round((float) ($data['monto_esperado'] ?? 0), 2) : null;

            if ($preparado) {
                abort_if($saldo <= 0, 422, 'La orden ya está pagada y no necesita cambio.');
                abort_if($montoEsperado < $saldo, 422, 'El monto que entregará el cliente no puede ser menor al saldo por cobrar.');
            }

            $orden->update([
                'delivery_monto_esperado' => $montoEsperado,
                'delivery_cambio_preparado' => $preparado,
                'delivery_cambio_preparado_por' => $preparado ? auth('api')->id() : null,
                'delivery_cambio_preparado_en' => $preparado ? now() : null,
            ]);

            return $orden->fresh(['pagos', 'deliveryCambioPreparadoPor:id,name']);
        });

        event(ServicioFichaActualizadaEvent::desdeOrden($orden, 'cambio_delivery'));
        $saldo = round((float) $orden->saldo_pendiente, 2);
        $monto = $orden->delivery_monto_esperado !== null ? round((float) $orden->delivery_monto_esperado, 2) : null;

        return response()->json([
            'message' => $orden->delivery_cambio_preparado ? 'Cambio preparado.' : 'Preparación de cambio anulada.',
            'orden_id' => $orden->id,
            'saldo_pendiente' => $saldo,
            'delivery_monto_esperado' => $monto,
            'delivery_cambio_preparado' => (bool) $orden->delivery_cambio_preparado,
            'delivery_cambio' => $monto !== null ? round(max(0, $monto - $saldo), 2) : null,
            'delivery_cambio_preparado_por_nombre' => $orden->deliveryCambioPreparadoPor?->name,
            'delivery_cambio_preparado_en' => $orden->delivery_cambio_preparado_en?->toIso8601String(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * Soporta creación desde POS con cliente_nombre y cliente_telefono
     */
    public function store(Request $request)
    {
        app(\App\Services\ConfiguracionService::class)->aplicarFechaTrabajo($request, true);
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'nullable|exists:clientes,id',
            'cliente_nombre' => 'nullable|string|max:255',
            'cliente_telefono' => 'nullable|string|max:50',
            'mesa_id' => 'nullable|exists:mesas,id',
            'tipo_orden' => 'nullable|in:dine-in,to-go,delivery',
            'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'tipo_flujo' => 'nullable|in:normal,preorden',
            'fecha_programada' => 'nullable|required_if:tipo_flujo,preorden|date_format:Y-m-d\TH:i:s|after:now',
            'subtotal' => 'required|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'observaciones' => 'nullable|string|max:500',
            'reserva_sesion_id' => 'nullable|uuid',
            'operacion_cliente_id' => 'nullable|uuid',
            'usuario_origen_id' => 'nullable|integer|exists:users,id',
            'caja_id' => 'nullable|integer|exists:cajas,id',
            'venta_sin_conexion' => 'nullable|boolean',
            'pagos' => 'nullable|array|max:10',
            'pagos.*.metodo_pago' => 'required_with:pagos|in:efectivo,qr',
            'pagos.*.monto_aplicado' => 'required_with:pagos|numeric|min:0.01',
            'pagos.*.monto_recibido' => 'nullable|numeric|min:0.01',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
            'items.*.combinacion_id' => 'nullable|integer|exists:producto_combinaciones,id',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if ($request->filled('usuario_origen_id') && (int) $request->usuario_origen_id !== (int) auth('api')->id()) {
            return response()->json(['message' => 'Esta venta pendiente pertenece a otro cajero.'], 403);
        }
        if ($request->filled('operacion_cliente_id')) {
            $existente = Orden::where('operacion_cliente_id', $request->operacion_cliente_id)->first();
            if ($existente) return $this->respuestaVentaCreada($existente, true);
        }
        if ($this->esMesero()) {
            abort_unless($request->input('tipo_flujo') === 'preorden', 403, 'El mesero solamente puede registrar preórdenes.');
        }
        if (!$request->cliente_id && !$request->cliente_nombre) {
            return response()->json(['message' => 'El cliente es obligatorio para crear una orden.'], 422);
        }
        $productos = Producto::with(['estacion', 'opciones.modificador', 'configuracionesModificador', 'combinaciones.opciones'])
            ->whereIn('id', collect($request->items)->pluck('producto_id')->unique())
            ->get()
            ->keyBy('id');
        foreach ($request->items as $item) {
            $producto = $productos->get($item['producto_id']);
            if (!$producto || !$producto->estacion_id || !$producto->estacion?->activa) {
                return response()->json([
                    'message' => 'El producto seleccionado no tiene una estación de trabajo activa: ' . ($producto?->nombre ?? $item['producto_id']) . '.',
                ], 422);
            }

            try {
                $this->validarModificadoresProducto($producto, $item['modificadores'] ?? []);
                $this->resolverCombinacion($producto, $item['modificadores'] ?? [], $item['combinacion_id'] ?? null);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        DB::beginTransaction();
        try {
            // Bloqueamos el stock en un orden estable: reserva y venta no pueden
            // confirmar las últimas unidades al mismo tiempo.
            $productos = Producto::with(['estacion', 'opciones', 'combinaciones.opciones'])->whereIn('id', collect($request->items)->pluck('producto_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($request->items as $item) $this->validarPrecioDisponible($productos->get($item['producto_id']), $item);
            $usoOpciones = collect($request->items)->flatMap(function (array $item) {
                return collect($item['modificadores'] ?? [])->map(fn (array $opcion) => [
                    'id' => (int) $opcion['modificador_opcion_id'],
                    'cantidad' => (int) $item['cantidad'],
                ]);
            })->groupBy('id')->map(fn ($opciones) => $opciones->sum('cantidad'));
            $opcionesConStock = ModificadorOpcion::whereIn('id', $usoOpciones->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($usoOpciones as $opcionId => $cantidad) {
                $opcion = $opcionesConStock->get($opcionId);
                if ($opcion?->maneja_stock && $opcion->stock !== null && (int) $opcion->stock < $cantidad) {
                    throw new \RuntimeException('No hay suficientes unidades de ' . $opcion->nombre . '.');
                }
            }
            $userActual = auth('api')->user();
            $clienteId = null;
            if ($request->cliente_id) {
                $clienteId = $request->cliente_id;
            }
            elseif ($request->cliente_nombre) {
                $clienteId = $this->resolverClientePorNombre($request->cliente_nombre, $request->cliente_telefono);
            }

            $tipoFlujo = $request->input('tipo_flujo', $request->filled('fecha_programada') ? 'preorden' : 'normal');
            $fechaOrden = $request->filled('fecha_orden')
                ? Carbon::createFromFormat('Y-m-d\TH:i:s', $request->fecha_orden)
                : now();

            $numeroOrden = Orden::siguienteNumeroParaFecha($fechaOrden->toDateString());

            // Crear la orden
            $orden = Orden::create([
                'operacion_cliente_id' => $request->input('operacion_cliente_id'),
                'user_id' => $userActual->id,
                'cliente_id' => $clienteId,
                'mesa_id' => ($request->tipo_orden ?? 'dine-in') === 'dine-in' ? $request->mesa_id : null,
                'numero_orden' => $numeroOrden,
                'fecha_orden' => $request->filled('fecha_orden') ? $request->fecha_orden : null,
                'fecha_programada' => $tipoFlujo === 'preorden' ? $request->fecha_programada : null,
                'tipo_flujo' => $tipoFlujo,
                'estado_preorden' => $tipoFlujo === 'preorden' ? 'programada' : null,
                'subtotal' => $request->subtotal,
                'descuento' => $request->descuento ?? 0,
                'total' => $request->total,
                'estado' => 'pendiente',
                'observaciones' => $request->observaciones,
                'tipo_orden' => $request->tipo_orden ?? 'dine-in',
            ]);

            // Crear detalles de la orden (items del carrito)
            foreach ($request->items as $item) {
                $producto = $productos->get($item['producto_id']);
                $combinacion = $this->resolverCombinacion($producto, $item['modificadores'] ?? [], $item['combinacion_id'] ?? null);

                if ($producto->maneja_stock && $producto->stock !== null) {
                    $cantidadSolicitada = (int) $item['cantidad'];
                    if ((int) $producto->stock < $cantidadSolicitada) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'No hay suficiente stock para el producto ' . $producto->nombre . '.'
                        ], 422);
                    }

                    $producto->stock = max(0, (int) $producto->stock - $cantidadSolicitada);
                    $producto->save();
                }

                foreach ($item['modificadores'] ?? [] as $modificador) {
                    $opcion = $opcionesConStock->get((int) $modificador['modificador_opcion_id']);
                    if ($opcion?->maneja_stock && $opcion->stock !== null) {
                        $opcion->decrement('stock', (int) $item['cantidad']);
                    }
                }

                // Cada unidad es un detalle independiente para que Cocina, Parrilla y Servicio
                // puedan finalizarla sin afectar a las demás unidades del mismo producto.
                for ($unidad = 0; $unidad < (int) $item['cantidad']; $unidad++) {
                    $ordenDetalle = OrdenDetalle::create([
                        'orden_id' => $orden->id,
                        'producto_id' => $item['producto_id'],
                        'producto_combinacion_id' => $combinacion?->id,
                        'combinacion_nombre' => $combinacion?->nombre,
                        'estacion_id' => $producto->estacion_id,
                        'cantidad' => 1,
                        'precio_unitario' => $item['precio_unitario'],
                        'nota' => $item['nota'] ?? null,
                        'estado_cocina' => 'pendiente',
                    ]);

                    if (isset($item['modificadores']) && is_array($item['modificadores'])) {
                        foreach ($item['modificadores'] as $modificador) {
                            OrdenDetalleOpcion::create([
                                'orden_detalle_id' => $ordenDetalle->id,
                                'modificador_opcion_id' => $modificador['modificador_opcion_id'],
                                'precio_extra' => $modificador['precio_extra'] ?? 0,
                            ]);
                        }
                    }

                    // Las estaciones secundarias dependen de las opciones elegidas.
                    // Sincronizar al final evita calcularlas con la relación de opciones
                    // todavía vacía o parcialmente cargada.
                    app(\App\Services\KdsEstacionService::class)->sincronizarDetalle($ordenDetalle->fresh());
                }
            }

            if ($request->filled('reserva_sesion_id')) {
                ReservaStock::where('sesion_id', $request->reserva_sesion_id)->delete();
                ReservaStockModificador::where('sesion_id', $request->reserva_sesion_id)->delete();
            }

            $cajaPagoId = $this->registrarPagosIniciales(
                $orden,
                $request->input('pagos', []),
                $request->integer('caja_id') ?: null,
                $request->boolean('venta_sin_conexion')
            );

            DB::commit();

            if ($cajaPagoId !== null) {
                $this->emitirEventoSeguro(new CajaActualizadaEvent($cajaPagoId, 'venta_registrada'), 'venta_registrada', $orden->id);
            }

            if ($orden->esPreordenProgramada()) {
                $this->emitirEventoSeguro(new PreordenActualizadaEvent($orden, 'preorden_creada'), 'preorden_creada', $orden->id);
            } else {
                $this->emitirEventoSeguro(new OrdenCreadaEvent($orden), 'orden_creada', $orden->id);
            }

            return $this->respuestaVentaCreada($orden, false);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (QueryException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($request->filled('operacion_cliente_id') && (string) $e->getCode() === '23000') {
                $existente = Orden::where('operacion_cliente_id', $request->operacion_cliente_id)->first();
                if ($existente) return $this->respuestaVentaCreada($existente, true);
            }
            return response()->json(['message' => 'Error al crear la orden', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function registrarPagosIniciales(Orden $orden, array $pagos, ?int $cajaSolicitadaId, bool $ventaSinConexion): ?int
    {
        if ($pagos === []) return null;

        $partes = collect($pagos)->map(function (array $pago) {
            $aplicado = round((float) $pago['monto_aplicado'], 2);
            $recibido = $pago['metodo_pago'] === 'qr'
                ? $aplicado
                : round((float) ($pago['monto_recibido'] ?? $aplicado), 2);
            if ($recibido < $aplicado) throw new \RuntimeException('En efectivo, el monto recibido no puede ser menor al monto aplicado.');
            return ['metodo_pago' => $pago['metodo_pago'], 'monto_aplicado' => $aplicado, 'monto_recibido' => $recibido];
        });

        $totalAplicado = round((float) $partes->sum('monto_aplicado'), 2);
        if ($totalAplicado > round((float) $orden->total, 2)) {
            throw new \RuntimeException('Los pagos superan el total de la orden.');
        }

        $cajaId = null;
        if ($partes->contains(fn (array $pago) => $pago['metodo_pago'] === 'efectivo')) {
            $usuarioId = (int) auth('api')->id();
            $caja = Caja::query()
                ->when($cajaSolicitadaId, fn ($query) => $query->whereKey($cajaSolicitadaId))
                ->where(function ($query) use ($usuarioId) {
                    $query->where('user_id', $usuarioId)
                        ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
                })
                ->when(!$ventaSinConexion, fn ($query) => $query->where('estado', 'abierta'))
                ->lockForUpdate()
                ->first();
            if (!$caja) throw new \RuntimeException('No tienes acceso a la caja usada para registrar el pago en efectivo.');
            $cajaId = $caja->id;
        }

        foreach ($partes as $parte) {
            PagoOrden::create([
                'id_orden' => $orden->id,
                'caja_id' => $parte['metodo_pago'] === 'efectivo' ? $cajaId : null,
                'user_id' => auth('api')->id(),
                'monto_recibido' => $parte['monto_recibido'],
                'monto_pagado' => $parte['monto_aplicado'],
                'cambio_devuelto' => round($parte['monto_recibido'] - $parte['monto_aplicado'], 2),
                'metodo_pago' => $parte['metodo_pago'],
                'tipo_pago' => 'pago',
                'fecha_pago' => now(),
            ]);
        }

        $orden->estado_pago = $totalAplicado <= 0
            ? 'pendiente'
            : ($totalAplicado < (float) $orden->total ? 'parcial' : 'completado');
        $orden->save();
        return $cajaId;
    }

    private function respuestaVentaCreada(Orden $orden, bool $duplicada)
    {
        return response()->json([
            'message' => $duplicada ? 'La venta ya había sido registrada.' : 'Orden creada exitosamente',
            'duplicada' => $duplicada,
            'orden' => $orden->load('user', 'cliente', 'mesa', 'pagos', 'detalles.producto.combinaciones.opciones', 'detalles.estacion', 'detalles.opciones.modificadorOpcion'),
        ], $duplicada ? 200 : 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $orden = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto.combinaciones.opciones', 'detalles.estacion', 'detalles.opciones.modificadorOpcion')->findOrFail($id);
        $this->autorizarMeseroSobrePreorden($orden);
        
        if (!$orden) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        return response()->json([
            'orden' => $orden
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        app(\App\Services\ConfiguracionService::class)->aplicarFechaTrabajo($request, false);
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'nullable|exists:clientes,id',
            'cliente_nombre' => 'nullable|string|max:255',
            'cliente_telefono' => 'nullable|string|max:50',
            'mesa_id' => 'nullable|exists:mesas,id',
            'tipo_orden' => 'nullable|in:dine-in,to-go,delivery',
            // 'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'tipo_flujo' => 'nullable|in:normal,preorden',
            'fecha_programada' => 'nullable|date_format:Y-m-d\TH:i:s',
            'subtotal' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'total' => 'nullable|numeric|min:0',
            'estado' => 'nullable|in:pendiente,preparando,listo,entregado,cancelado',
            'observaciones' => 'nullable|string|max:500',
            'expected_version' => 'required|integer|min:1',
            'items' => 'nullable|array|min:1',
            'items.*.producto_id' => 'required_with:items|exists:productos,id',
            'items.*.orden_detalle_id' => 'nullable|integer',
            'items.*.cantidad' => 'required_with:items|integer|min:1',
            'items.*.precio_unitario' => 'required_with:items|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
            'items.*.combinacion_id' => 'nullable|integer|exists:producto_combinaciones,id',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $historialIds = [];
            $ordenActualizada = DB::transaction(function () use ($request, $id, &$historialIds) {
                $orden = Orden::lockForUpdate()->findOrFail($id);
                $esSolicitudCliente = $orden->origen_registro === 'cliente' && in_array($orden->estado_solicitud, ['pendiente', 'vencida'], true);
                abort_if((int) $orden->version !== (int) $request->expected_version, 409,
                    'Esta orden fue modificada por otro cajero. Actualízala antes de guardar para no perder cambios.');
                abort_if($orden->estado === 'cancelado' && !$esSolicitudCliente, 422,
                    'Una orden cancelada no puede editarse. Crea una nueva orden si el cliente vuelve a pedir.');
                $this->autorizarMeseroSobrePreorden($orden);
                $usuarioId = auth('api')->id();
                $estadoAnterior = $orden->estado;

                $clienteId = $orden->cliente_id;
                if ($request->filled('cliente_id')) {
                    $clienteId = $request->cliente_id;
                } elseif ($request->filled('cliente_nombre')) {
                    $clienteId = $this->resolverClientePorNombre($request->cliente_nombre, $request->cliente_telefono);
                }

                if (!$clienteId) {
                    throw new \InvalidArgumentException('El cliente es obligatorio para actualizar una orden.');
                }

                $updateData = [];
                foreach (['mesa_id', 'tipo_orden', 'subtotal', 'observaciones'] as $campo) {
                    if ($request->has($campo)) {
                        $updateData[$campo] = $request->input($campo);
                    }
                }
                $tipoOrdenActualizado = $updateData['tipo_orden'] ?? $orden->tipo_orden;
                if ($tipoOrdenActualizado !== 'dine-in') {
                    $updateData['mesa_id'] = null;
                }
                if ($request->has('cliente_id') || $request->filled('cliente_nombre')) {
                    $updateData['cliente_id'] = $clienteId;
                }
                if ($request->has('fecha_orden')) {
                    $updateData['fecha_orden'] = $request->filled('fecha_orden') ? $request->fecha_orden : null;
                }
                if ($request->has('tipo_flujo') || $request->has('fecha_programada')) {
                    abort_if($orden->estado_preorden === 'cancelada' && !$esSolicitudCliente, 422, 'Una preorden cancelada no puede modificarse.');
                    $tipoFlujo = $request->input('tipo_flujo', $request->filled('fecha_programada') ? 'preorden' : 'normal');
                    if ($orden->estado_preorden === 'activada' && $tipoFlujo !== 'preorden') {
                        abort(422, 'Una preorden activada no puede convertirse en pedido normal.');
                    }
                    if ($tipoFlujo === 'preorden' && $orden->estado_preorden !== 'activada') {
                        abort_unless($request->filled('fecha_programada'), 422, 'La fecha programada es obligatoria para una preorden.');
                        // Una preorden existente puede necesitar correcciones después
                        // de su hora programada. La restricción de hora futura sólo se
                        // aplica al convertir una orden normal en una nueva preorden.
                        if ($orden->tipo_flujo !== 'preorden') {
                            abort_unless(Carbon::createFromFormat('Y-m-d\TH:i:s', $request->fecha_programada)->isFuture(), 422, 'La fecha programada debe ser futura.');
                        }
                    }
                    $updateData['tipo_flujo'] = $tipoFlujo;
                    $updateData['fecha_programada'] = $tipoFlujo === 'preorden' ? $request->input('fecha_programada', $orden->fecha_programada) : null;
                    $updateData['estado_preorden'] = $tipoFlujo === 'preorden'
                        ? ($orden->estado_preorden ?: 'programada')
                        : null;
                }
                if ($request->has('descuento')) {
                    $updateData['descuento'] = $request->descuento ?? 0;
                }
                if ($request->has('total')) {
                    $updateData['total'] = $request->total;
                }
                if (($request->has('total') && (float) $request->total !== (float) $orden->total)
                    || ($request->has('tipo_orden') && $request->tipo_orden !== $orden->tipo_orden)) {
                    $updateData['delivery_monto_esperado'] = null;
                    $updateData['delivery_cambio_preparado'] = false;
                    $updateData['delivery_cambio_preparado_por'] = null;
                    $updateData['delivery_cambio_preparado_en'] = null;
                }
                if ($request->has('estado')) {
                    $updateData['estado'] = $request->estado;
                }

                if (!empty($updateData)) {
                    $orden->update($updateData);
                }

                if ($estadoAnterior !== $orden->estado) {
                    $this->registrarCambio(
                        $orden,
                        null,
                        null,
                        $orden->estado === 'cancelado' ? 'orden_cancelada' : 'estado_cambiado',
                        null,
                        null,
                        ['estado' => $estadoAnterior],
                        ['estado' => $orden->estado],
                        $usuarioId,
                        $historialIds,
                    );
                }

                if ($request->has('items')) {
                    if ($esSolicitudCliente) {
                        ReservaStock::where('sesion_id', $orden->codigo_publico)->delete();
                        ReservaStockModificador::where('sesion_id', $orden->codigo_publico)->delete();
                    }
                    $huboUnidadesNuevas = $this->sincronizarDetallesOrden($orden, $request->items, $usuarioId, $historialIds, !$esSolicitudCliente);
                    if ($huboUnidadesNuevas && in_array($orden->estado, ['listo', 'entregado'], true)) {
                        // Una unidad adicional vuelve a abrir trabajo sin tocar los estados
                        // de las unidades que Cocina/Parrilla ya finalizaron.
                        $orden->update([
                            'estado' => 'preparando',
                            'entregada_en' => null,
                        ]);
                    }
                }
                if ($esSolicitudCliente) $this->reservarSolicitudClienteEditada($orden);

                $pagosTotales = PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado');
                $orden->estado_pago = $pagosTotales <= 0
                    ? 'pendiente'
                    : ($pagosTotales < (float) $orden->total ? 'parcial' : 'completado');
                $orden->version = (int) $orden->version + 1;
                $orden->save();

                return $orden->fresh([
                    'user', 'cliente', 'mesa', 'pagos', 'detalles.producto.categoria', 'detalles.producto.combinaciones.opciones', 'detalles.estacion',
                    'detalles.opciones.modificadorOpcion',
                ]);
            });

            if ($ordenActualizada->esPreordenProgramada()) {
                $this->emitirEventoSeguro(new PreordenActualizadaEvent($ordenActualizada), 'preorden_actualizada', $ordenActualizada->id);
            } else {
                $this->emitirEventoSeguro(
                    new OrdenCocinaActualizadaEvent($ordenActualizada, $historialIds),
                    'orden_actualizada',
                    $ordenActualizada->id
                );
            }

            return response()->json([
                'message' => 'Orden actualizada exitosamente',
                'orden' => $ordenActualizada,
            ], 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            if ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                return response()->json([
                    'message' => $e->getMessage()
                ], 422);
            }

            return response()->json([
                'message' => 'Error al actualizar la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancela una venta sin borrar su evidencia: mantiene productos y pagos originales,
     * repone stock y registra una devolución que deja el saldo financiero en cero.
     */
    public function informacionCancelacion(string $id)
    {
        $orden = Orden::findOrFail($id);
        $pagado = round((float) PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado'), 2);
        $pagoOrigen = $this->pagoOrigenDeOrden($orden->id);
        $cajaActual = $this->cajaAbiertaDelUsuario((int) auth('api')->id());
        $disponible = $cajaActual ? $this->efectivoDisponible($cajaActual) : 0;

        return response()->json([
            'monto_devolucion' => $pagado,
            'pago_origen_id' => $pagoOrigen?->id,
            'caja_origen_id' => $pagoOrigen?->caja_id,
            'caja_origen_estado' => $pagoOrigen?->caja?->estado,
            'caja_actual_id' => $cajaActual?->id,
            'efectivo_disponible' => $disponible,
            'faltante_efectivo' => max(0, round($pagado - $disponible, 2)),
        ]);
    }

    public function cancelarVenta(Request $request, string $id)
    {
        $request->validate([
            'expected_version' => 'required|integer|min:1',
            'metodo_pago' => 'nullable|in:efectivo,qr',
        ]);

        try {
            [$ordenCancelada, $cajaId, $historialIds, $stocksRepuestos] = DB::transaction(function () use ($request, $id) {
                $orden = Orden::lockForUpdate()->findOrFail($id);

                abort_if((int) $orden->version !== (int) $request->expected_version, 409,
                    'Esta orden fue modificada por otro cajero. Actualízala antes de cancelarla.');
                abort_if($orden->estado === 'cancelado', 422, 'La orden ya fue cancelada.');

                $pagado = round((float) PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado'), 2);
                $pagoOrigen = $this->pagoOrigenDeOrden($orden->id);
                $cajaId = null;

                if ($pagado > 0) {
                    abort_unless($request->filled('metodo_pago'), 422,
                        'Selecciona el método con el que se realizará la devolución.');

                    if ($request->metodo_pago === 'efectivo') {
                        $usuarioId = auth('api')->id();
                        $caja = $this->cajaAbiertaDelUsuario((int) $usuarioId, true);

                        abort_unless($caja, 422, 'No tienes acceso a una caja abierta para devolver efectivo.');
                        $disponible = $this->efectivoDisponible($caja);
                        if ($disponible < $pagado) {
                            $faltante = round($pagado - $disponible, 2);
                            $origen = $pagoOrigen?->caja_id ? " El cobro original corresponde a la caja #{$pagoOrigen->caja_id}." : '';
                            abort(422, 'La caja actual #'.$caja->id.' solo dispone de '.$this->montoLegible($disponible)
                                .' y la devolución requiere '.$this->montoLegible($pagado).'.'.$origen
                                .' Registra un ingreso o traslado autorizado de '.$this->montoLegible($faltante).' antes de cancelar.');
                        }
                        $cajaId = $caja->id;
                    }
                }

                $stocksRepuestos = [];
                foreach (OrdenDetalle::with(['producto', 'opciones.modificadorOpcion'])->where('orden_id', $orden->id)->get() as $detalle) {
                    $producto = Producto::lockForUpdate()->find($detalle->producto_id);
                    if ($producto && $producto->maneja_stock && $producto->stock !== null) {
                        $producto->increment('stock', (int) $detalle->cantidad);
                        $stocksRepuestos[$producto->id] = (int) $producto->fresh()->stock;
                    }
                    foreach ($detalle->opciones as $opcionDetalle) {
                        $opcion = ModificadorOpcion::lockForUpdate()->find($opcionDetalle->modificador_opcion_id);
                        if ($opcion?->maneja_stock && $opcion->stock !== null) $opcion->increment('stock', (int) $detalle->cantidad);
                    }
                }

                if ($pagado > 0) {
                    PagoOrden::create([
                        'id_orden' => $orden->id,
                        'caja_id' => $cajaId,
                        'user_id' => auth('api')->id(),
                        'monto_recibido' => -$pagado,
                        'monto_pagado' => -$pagado,
                        'cambio_devuelto' => 0,
                        'metodo_pago' => $request->metodo_pago,
                        'tipo_pago' => 'devolucion',
                        'fecha_pago' => now(),
                        'pago_origen_id' => $pagoOrigen?->id,
                        'caja_origen_id' => $pagoOrigen?->caja_id,
                    ]);
                }

                $historialIds = [];
                $estadoAnterior = $orden->estado;
                $orden->update([
                    'estado' => 'cancelado',
                    // Una orden cancelada no debe reaparecer como deuda pendiente.
                    'estado_pago' => 'completado',
                    'version' => (int) $orden->version + 1,
                ]);
                $this->registrarCambio(
                    $orden, null, null, 'orden_cancelada', null, null,
                    ['estado' => $estadoAnterior, 'monto_pagado' => $pagado],
                    ['estado' => 'cancelado', 'monto_devuelto' => $pagado],
                    auth('api')->id(), $historialIds,
                );

                return [$orden->fresh(['pagos', 'detalles.producto']), $cajaId, $historialIds, $stocksRepuestos];
            });

            $this->emitirEventoSeguro(new OrdenCocinaActualizadaEvent($ordenCancelada, $historialIds), 'orden_cancelada', $ordenCancelada->id);
            if ($cajaId) {
                event(new CajaActualizadaEvent($cajaId, 'devolucion_orden'));
            }
            foreach ($stocksRepuestos as $productoId => $stockFinal) {
                event(new StockActualizadoEvent((int) $productoId, (int) $stockFinal));
            }

            return response()->json([
                'message' => 'Orden cancelada y devolución registrada correctamente.',
                'orden' => $ordenCancelada,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo cancelar la orden.', 'error' => $e->getMessage()], 500);
        }
    }

    private function pagoOrigenDeOrden(int $ordenId): ?PagoOrden
    {
        return PagoOrden::with('caja')
            ->where('id_orden', $ordenId)
            ->where('monto_pagado', '>', 0)
            ->orderByDesc('id')
            ->first();
    }

    private function cajaAbiertaDelUsuario(int $usuarioId, bool $bloquear = false): ?Caja
    {
        $query = Caja::where(function ($query) use ($usuarioId) {
                $query->where('user_id', $usuarioId)
                    ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
            })
            ->where('estado', 'abierta');

        if ($bloquear) $query->lockForUpdate();

        return $query->first();
    }

    private function efectivoDisponible(Caja $caja): float
    {
        $pagos = round((float) $caja->pagos()->where('metodo_pago', 'efectivo')->sum('monto_pagado'), 2);
        $ingresos = round((float) $caja->movimientos()->where('estado', 'ACTIVO')->where('tipo', 'INGRESO')->sum('monto'), 2);
        $retiros = round((float) $caja->movimientos()->where('estado', 'ACTIVO')->where('tipo', 'RETIRO')->sum('monto'), 2);
        $gastos = round((float) $caja->gastos()->where('estado', 'ACTIVO')->sum('monto'), 2);

        return round((float) $caja->monto_apertura + $pagos + $ingresos - $retiros - $gastos, 2);
    }

    private function montoLegible(float $monto): string
    {
        return 'Bs '.number_format($monto, 2, ',', '.');
    }

    public function activarPreorden(string $id, \App\Services\PreordenActivationService $activacion)
    {
        try {
            $preorden = Orden::findOrFail($id);
            abort_if($preorden->tipo_orden === 'delivery', 422, 'El delivery se habilita automáticamente 3 minutos antes de la hora programada.');
            $orden = $activacion->activar((int) $id, auth('api')->id());

            return response()->json(['message' => 'Llegada confirmada. La preorden ya está activa.', 'orden' => $orden]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo activar la preorden.', 'error' => $e->getMessage()], 500);
        }
    }

    /** El broadcasting es complementario: nunca invalida una escritura ya confirmada. */
    private function emitirEventoSeguro(object $evento, string $tipo, int $ordenId): void
    {
        try {
            event($evento);
            if ($tipo === 'orden_creada') SendGrillOrderPush::dispatch($ordenId)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('No se pudo publicar la notificación en tiempo real de la orden.', [
                'tipo' => $tipo,
                'orden_id' => $ordenId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Compara los detalles persistidos con el payload del POS sin recrearlos. */
    private function validarPrecioDisponible(?Producto $producto, array $item): void
    {
        if (!$producto || !$producto->activo) throw new \RuntimeException(($producto?->nombre ?? 'El producto').' está desactivado. Retíralo del carrito.');
        if (round((float) $producto->precio, 2) !== round((float) $item['precio_unitario'], 2)) {
            throw new \RuntimeException('El precio de '.$producto->nombre.' cambió a '.$producto->precio.'. Actualiza el carrito antes de continuar.');
        }
    }

    private function sincronizarDetallesOrden(Orden $orden, array $items, ?int $usuarioId, array &$historialIds, bool $ajustarInventario = true): bool
    {
        // El POS puede enviar una línea con cantidad mayor a uno. Internamente cada unidad
        // debe conservar su propio detalle para que su producción y entrega sean independientes.
        $items = collect($items)->flatMap(function (array $item) {
            $cantidad = max(1, (int) $item['cantidad']);

            return collect(range(0, $cantidad - 1))->map(function (int $unidad) use ($item) {
                $unidadItem = $item;
                $unidadItem['cantidad'] = 1;
                if ($unidad > 0) {
                    unset($unidadItem['orden_detalle_id']);
                }

                return $unidadItem;
            });
        })->values()->all();

        $detallesExistentes = $orden->detalles()
            ->with(['producto', 'estacion', 'opciones.modificadorOpcion'])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $idsSolicitados = collect($items)
            ->pluck('orden_detalle_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id);
        if ($idsSolicitados->duplicates()->isNotEmpty()
            || $idsSolicitados->contains(fn ($id) => !$detallesExistentes->has($id))) {
            throw new \RuntimeException('Uno de los detalles enviados no pertenece a esta orden.');
        }

        // Una línea agrupada del POS conserva un ID y expande el resto como unidades nuevas.
        // Primero devolvemos al inventario las unidades antiguas que serán reemplazadas para
        // que el stock disponible no se valide dos veces durante una edición.
        $detallesExistentes
            ->filter(fn (OrdenDetalle $detalle) => !$idsSolicitados->contains((int) $detalle->id))
            ->each(function (OrdenDetalle $detalle) use ($orden, $usuarioId, &$historialIds, $ajustarInventario) {
                if ($ajustarInventario) {
                    $this->ajustarStock($detalle->producto, -(int) $detalle->cantidad);
                    $this->ajustarStockOpcionesPorIds($detalle->opciones->pluck('modificador_opcion_id')->all(), -1);
                }
                $this->registrarCambio(
                    $orden, $detalle, $detalle->producto, 'detalle_eliminado', (int) $detalle->cantidad,
                    null, $this->datosDetalle($detalle), null, $usuarioId, $historialIds,
                );
                $detalle->opciones()->delete();
                $detalle->delete();
            });

        $idsRecibidos = [];
        $huboUnidadesNuevas = false;

        foreach ($items as $item) {
            $detalleId = $item['orden_detalle_id'] ?? null;
            $productoNuevo = Producto::with(['estacion', 'opciones.modificador', 'configuracionesModificador', 'combinaciones.opciones'])->lockForUpdate()->findOrFail($item['producto_id']);
            $combinacion = $this->resolverCombinacion($productoNuevo, $item['modificadores'] ?? [], $item['combinacion_id'] ?? null);
            $existente = $detalleId ? $detallesExistentes->get($detalleId) : null;
            if (!$existente || $existente->producto_id !== $productoNuevo->id) {
                $this->validarPrecioDisponible($productoNuevo, $item);
                $this->asegurarEstacionActiva($productoNuevo);
                $this->validarModificadoresProducto($productoNuevo, $item['modificadores'] ?? []);
            } elseif (round((float) $existente->precio_unitario, 2) !== round((float) $item['precio_unitario'], 2)) {
                throw new \RuntimeException('El precio de un producto ya vendido debe conservarse.');
            } elseif ($this->opcionesCambian($existente, $item['modificadores'] ?? [])) {
                $this->validarModificadoresProducto($productoNuevo, $item['modificadores'] ?? []);
            }
            $cantidadNueva = (int) $item['cantidad'];

            if ($detalleId) {
                if (isset($idsRecibidos[$detalleId]) || !$detallesExistentes->has($detalleId)) {
                    throw new \RuntimeException('El detalle enviado no pertenece a esta orden.');
                }
                $idsRecibidos[$detalleId] = true;
                /** @var OrdenDetalle $detalle */
                $detalle = $detallesExistentes->get($detalleId);
                $productoAnterior = $detalle->producto;
                $datosAnterior = $this->datosDetalle($detalle);

                if (!$ajustarInventario) {
                    // Las solicitudes todavía no descontaron existencias; se reservan al terminar la edición.
                } elseif ($detalle->producto_id !== $productoNuevo->id) {
                    $this->ajustarStock($productoAnterior, -(int) $detalle->cantidad);
                    $this->ajustarStock($productoNuevo, $cantidadNueva);
                } else {
                    $this->ajustarStock($productoNuevo, $cantidadNueva - (int) $detalle->cantidad);
                }

                $cambioCantidad = (int) $detalle->cantidad !== $cantidadNueva;
                $cambioBase = $cambioCantidad
                    || $detalle->producto_id !== $productoNuevo->id
                    || $detalle->producto_combinacion_id !== $combinacion?->id
                    || ($detalle->combinacion_nombre ?? null) !== ($combinacion?->nombre ?? null)
                    || (float) $detalle->precio_unitario !== (float) $item['precio_unitario']
                    || ($detalle->nota ?? null) !== ($item['nota'] ?? null);

                $detalle->fill([
                    'producto_id' => $productoNuevo->id,
                    'producto_combinacion_id' => $combinacion?->id,
                    'combinacion_nombre' => $combinacion?->nombre,
                    // Si se sustituye el producto, nace un nuevo snapshot de estación.
                    // En cambios de cantidad/precio se mantiene intacto el snapshot original.
                    'estacion_id' => $detalle->producto_id !== $productoNuevo->id
                        ? $productoNuevo->estacion_id
                        : $detalle->estacion_id,
                    'cantidad' => $cantidadNueva,
                    'precio_unitario' => $item['precio_unitario'],
                    'nota' => $item['nota'] ?? null,
                ]);
                if ($cantidadNueva > (int) $datosAnterior['cantidad'] || $detalle->producto_id !== $datosAnterior['producto_id']) {
                    $detalle->estado_cocina = 'pendiente';
                    $detalle->fecha_servido = null;
                }
                $detalle->save();

                $opcionesCambiaron = $this->opcionesCambian($detalle, $item['modificadores'] ?? []);
                if ($opcionesCambiaron) {
                    if ($ajustarInventario) {
                        $this->ajustarStockOpcionesPorIds($detalle->opciones->pluck('modificador_opcion_id')->all(), -1);
                        $this->ajustarStockOpcionesPorIds(collect($item['modificadores'] ?? [])->pluck('modificador_opcion_id')->all(), 1);
                    }
                    $detalle->opciones()->delete();
                    $this->crearOpcionesDetalle($detalle, $item['modificadores'] ?? []);
                }

                if ($cambioBase || $opcionesCambiaron) {
                    $this->registrarCambio(
                        $orden,
                        $detalle,
                        $productoNuevo,
                        'detalle_modificado',
                        (int) $datosAnterior['cantidad'],
                        $cantidadNueva,
                        $datosAnterior,
                        $this->datosDetalle($detalle),
                        $usuarioId,
                        $historialIds,
                    );
                }
                continue;
            }

            if ($ajustarInventario) {
                $this->ajustarStock($productoNuevo, $cantidadNueva);
                $this->ajustarStockOpcionesPorIds(collect($item['modificadores'] ?? [])->pluck('modificador_opcion_id')->all(), 1);
            }
            $detalle = OrdenDetalle::create([
                'orden_id' => $orden->id,
                'producto_id' => $productoNuevo->id,
                'producto_combinacion_id' => $combinacion?->id,
                'combinacion_nombre' => $combinacion?->nombre,
                'estacion_id' => $productoNuevo->estacion_id,
                'cantidad' => $cantidadNueva,
                'precio_unitario' => $item['precio_unitario'],
                'nota' => $item['nota'] ?? null,
            ]);
            $this->crearOpcionesDetalle($detalle, $item['modificadores'] ?? []);
            // OrdenDetalle sincroniza su estación principal al guardarse. Esta segunda
            // sincronización ocurre con todas las opciones ya persistidas y garantiza
            // las dependencias de modificadores para la nueva unidad.
            app(\App\Services\KdsEstacionService::class)->sincronizarDetalle($detalle->fresh());
            $huboUnidadesNuevas = true;
            $this->registrarCambio(
                $orden,
                $detalle,
                $productoNuevo,
                'detalle_agregado',
                null,
                $cantidadNueva,
                null,
                $this->datosDetalle($detalle),
                $usuarioId,
                $historialIds,
            );
        }

        return $huboUnidadesNuevas;
    }

    private function ajustarStock(?Producto $producto, int $diferenciaCantidad): void
    {
        if (!$producto || !$producto->maneja_stock || $producto->stock === null || $diferenciaCantidad === 0) {
            return;
        }

        $producto = Producto::lockForUpdate()->findOrFail($producto->id);
        if ($diferenciaCantidad > 0 && (int) $producto->stock < $diferenciaCantidad) {
            throw new \RuntimeException('No hay suficiente stock para el producto ' . $producto->nombre . '.');
        }

        $producto->stock = (int) $producto->stock - $diferenciaCantidad;
        $producto->save();
    }

    private function asegurarEstacionActiva(Producto $producto): void
    {
        if (!$producto->estacion_id || !$producto->estacion?->activa) {
            throw new \RuntimeException('El producto ' . $producto->nombre . ' no tiene una estación de trabajo activa.');
        }
    }

    /** Valida pertenencia y la cantidad seleccionada en cada grupo. */
    private function validarModificadoresProducto(Producto $producto, array $modificadores): void
    {
        $elegidas = collect($modificadores)->pluck('modificador_opcion_id')->map(fn ($id) => (int) $id);
        $gruposActivos = $producto->opciones
            ->filter(fn ($opcion) => $opcion->modificador?->activo)
            ->groupBy('modificador_id');
        $opcionesActivas = $gruposActivos->flatten()->filter(fn ($opcion) => $opcion->activo);
        $permitidas = $opcionesActivas->pluck('id');
        if ($elegidas->diff($permitidas)->isNotEmpty()) {
            throw new \RuntimeException('Una opción elegida no pertenece al producto ' . $producto->nombre . '.');
        }
        $gruposActivos
            ->each(function ($opciones) use ($elegidas, $producto) {
                $modificador = $opciones->first()->modificador;
                $idsActivos = $opciones->filter(fn ($opcion) => $opcion->activo)->pluck('id');
                $cantidad = $elegidas->filter(fn ($id) => $idsActivos->contains($id))->count();
                $cantidadRequerida = $producto->configuracionesModificador->firstWhere('modificador_id', $modificador->id)?->cantidad_requerida;
                $cantidadEsMaxima = $modificador->usaLimiteMaximo();
                if ($cantidadRequerida !== null && $cantidadEsMaxima && $cantidad > (int) $cantidadRequerida) {
                    throw new \RuntimeException('Puedes elegir hasta ' . $cantidadRequerida . ' en “' . $modificador->nombre . '” para ' . $producto->nombre . '.');
                }
                if ($cantidadRequerida !== null && !$cantidadEsMaxima && $cantidad !== (int) $cantidadRequerida) {
                    throw new \RuntimeException('Debes elegir exactamente ' . $cantidadRequerida . ' en “' . $modificador->nombre . '” para ' . $producto->nombre . '.');
                }
                if ($cantidadRequerida === null && $modificador->requerido && $cantidad === 0) {
                    throw new \RuntimeException('Debes elegir una opción en “' . $modificador->nombre . '” para ' . $producto->nombre . '.');
                }
                if ($cantidadRequerida === null && $modificador->tipo === 'unico' && $cantidad > 1) {
                    throw new \RuntimeException('Solo puedes elegir una opción en “' . $modificador->nombre . '” para ' . $producto->nombre . '.');
                }
            });
    }

    /** $diferencia positiva descuenta; negativa devuelve al inventario compartido. */
    private function ajustarStockOpcionesPorIds(array $opcionIds, int $diferencia): void
    {
        if ($diferencia === 0 || empty($opcionIds)) return;
        foreach (collect($opcionIds)->countBy() as $opcionId => $cantidad) {
            $opcion = ModificadorOpcion::lockForUpdate()->findOrFail($opcionId);
            if ($opcion->maneja_stock && $opcion->stock !== null) {
                if ($diferencia > 0 && $opcion->stock < $cantidad * $diferencia) {
                    throw new \RuntimeException('No hay suficientes unidades de ' . $opcion->nombre . '.');
                }
                $opcion->stock -= $cantidad * $diferencia;
                $opcion->save();
            }
        }
    }

    private function crearOpcionesDetalle(OrdenDetalle $detalle, array $modificadores): void
    {
        foreach ($modificadores as $modificador) {
            OrdenDetalleOpcion::create([
                'orden_detalle_id' => $detalle->id,
                'modificador_opcion_id' => $modificador['modificador_opcion_id'],
                'precio_extra' => $modificador['precio_extra'] ?? 0,
            ]);
        }
    }

    private function opcionesCambian(OrdenDetalle $detalle, array $modificadores): bool
    {
        $actuales = $detalle->opciones
            ->map(fn ($opcion) => [(int) $opcion->modificador_opcion_id, (float) $opcion->precio_extra])
            ->sort()
            ->values()
            ->all();
        $nuevas = collect($modificadores)
            ->map(fn ($opcion) => [(int) $opcion['modificador_opcion_id'], (float) ($opcion['precio_extra'] ?? 0)])
            ->sort()
            ->values()
            ->all();

        return $actuales !== $nuevas;
    }

    private function resolverCombinacion(Producto $producto, array $modificadores, ?int $combinacionId = null): ?ProductoCombinacion
    {
        $producto->loadMissing(['opciones', 'combinaciones.opciones']);
        $seleccionadas = collect($modificadores)
            ->pluck('modificador_opcion_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($combinacionId !== null) {
            $solicitada = $producto->combinaciones->firstWhere('id', (int) $combinacionId);
            if (!$solicitada || !$solicitada->activo) {
                throw new \RuntimeException('La combinación seleccionada ya no está disponible para ' . $producto->nombre . '.');
            }
            return $solicitada;
        }

        return $producto->combinaciones->first(function (ProductoCombinacion $combinacion) use ($seleccionadas, $producto) {
            if (!$combinacion->activo) return false;
            $idsEsperados = $combinacion->opciones->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            $grupos = $combinacion->opciones->pluck('modificador_id')->map(fn ($id) => (int) $id)->unique();
            $idsDelGrupo = $producto->opciones->whereIn('modificador_id', $grupos)->pluck('id')->map(fn ($id) => (int) $id);
            $idsActuales = $seleccionadas->filter(fn ($id) => $idsDelGrupo->contains($id))->sort()->values();
            return $idsEsperados->all() === $idsActuales->all();
        });
    }

    private function datosDetalle(OrdenDetalle $detalle): array
    {
        $detalle->loadMissing(['producto', 'estacion', 'opciones.modificadorOpcion']);

        return [
            'detalle_id' => $detalle->id,
            'producto_id' => $detalle->producto_id,
            'producto_nombre' => $detalle->producto?->nombre,
            'combinacion_id' => $detalle->producto_combinacion_id,
            'combinacion_nombre' => $detalle->combinacion_nombre,
            'estacion_id' => $detalle->estacion_id,
            'estacion_nombre' => $detalle->estacion?->nombre,
            'cantidad' => (int) $detalle->cantidad,
            'precio_unitario' => (float) $detalle->precio_unitario,
            'nota' => $detalle->nota,
            'modificadores' => $detalle->opciones
                ->map(fn ($opcion) => $opcion->modificadorOpcion?->nombre)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function registrarCambio(
        Orden $orden,
        ?OrdenDetalle $detalle,
        ?Producto $producto,
        string $tipo,
        ?int $cantidadAnterior,
        ?int $cantidadNueva,
        ?array $datosAnterior,
        ?array $datosNuevo,
        ?int $usuarioId,
        array &$historialIds,
    ): void {
        $historial = HistorialCambioOrden::create([
            'orden_id' => $orden->id,
            'orden_detalle_id' => $detalle?->id,
            'user_id' => $usuarioId,
            'producto_id' => $producto?->id,
            'tipo_cambio' => $tipo,
            'cantidad_anterior' => $cantidadAnterior,
            'cantidad_nueva' => $cantidadNueva,
            'datos_anterior' => $datosAnterior,
            'datos_nuevo' => $datosNuevo,
        ]);
        $historialIds[] = $historial->id;
    }

    private function esMesero(): bool
    {
        return mb_strtolower(auth('api')->user()?->role?->nombre ?? '') === 'mesero';
    }

    private function resolverClientePorNombre(string $nombre, ?string $telefono = null): int
    {
        $nombre = trim($nombre);
        $cliente = Cliente::whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($nombre)])->first();
        if (!$cliente) {
            $cliente = Cliente::create(['nombre' => $nombre, 'telefono' => $telefono]);
        } elseif (!$cliente->telefono && $telefono) {
            $cliente->update(['telefono' => $telefono]);
        }
        return (int) $cliente->id;
    }

    private function reservarSolicitudClienteEditada(Orden $orden): void
    {
        $orden->load(['detalles.producto', 'detalles.opciones.modificadorOpcion']);
        abort_if(!$orden->fecha_programada || $orden->fecha_programada->lt(now()->addMinutes(20)), 422,
            'La hora solicitada debe quedar al menos 20 minutos después de la hora actual.');
        $expira = now()->addMinutes(20);
        foreach ($orden->detalles->groupBy('producto_id') as $productoId => $detalles) {
            $producto = Producto::lockForUpdate()->findOrFail($productoId); $cantidad = (int) $detalles->sum('cantidad');
            if ($producto->maneja_stock && $producto->stock !== null) {
                $reservado = (int) ReservaStock::activas()->where('producto_id', $productoId)->sum('cantidad');
                $disponible = max(0, (int) $producto->stock - $reservado);
                abort_if($cantidad > $disponible, 422, "Stock insuficiente de {$producto->nombre}: hay {$disponible} y la solicitud necesita {$cantidad}.");
                ReservaStock::create(['producto_id' => $productoId, 'usuario_id' => null, 'sesion_id' => $orden->codigo_publico, 'cantidad' => $cantidad, 'expira_en' => $expira]);
            }
        }
        $usos = [];
        foreach ($orden->detalles as $detalle) foreach ($detalle->opciones as $opcion) {
            $id = (int) $opcion->modificador_opcion_id;
            $usos[$id] = ($usos[$id] ?? 0) + (int) $detalle->cantidad;
        }
        foreach ($usos as $opcionId => $cantidad) {
            $opcion = ModificadorOpcion::lockForUpdate()->findOrFail($opcionId);
            if ($opcion->maneja_stock && $opcion->stock !== null) {
                $reservado = (int) ReservaStockModificador::activas()->where('modificador_opcion_id', $opcionId)->sum('cantidad');
                $disponible = max(0, (int) $opcion->stock - $reservado);
                abort_if($cantidad > $disponible, 422, "Stock insuficiente de {$opcion->nombre}: hay {$disponible} y la solicitud necesita {$cantidad}.");
                ReservaStockModificador::create(['modificador_opcion_id' => $opcionId, 'usuario_id' => null, 'sesion_id' => $orden->codigo_publico, 'cantidad' => $cantidad, 'expira_en' => $expira]);
            }
        }
        $orden->update(['user_id' => auth('api')->id(), 'estado_solicitud' => 'pendiente', 'estado_preorden' => 'programada', 'estado' => 'pendiente', 'solicitud_expira_en' => $expira]);
    }

    private function autorizarMeseroSobrePreorden(Orden $orden): void
    {
        if (!$this->esMesero()) return;
        if ($orden->origen_registro === 'cliente' && in_array($orden->estado_solicitud, ['pendiente', 'vencida'], true)) return;
        abort_unless(
            $orden->tipo_flujo === 'preorden'
            && $orden->estado_preorden === 'programada'
            && (int) $orden->user_id === (int) auth('api')->id(),
            403,
            'Solo puedes consultar o editar tus preórdenes programadas.'
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();

        try {
            $orden = Orden::findOrFail($id);

            if (!$orden) {
                return response()->json(['message' => 'Orden no encontrada'], 404);
            }

            foreach ($orden->detalles as $detalle) {
                $producto = \App\Models\Producto::find($detalle->producto_id);
                if ($producto && $producto->maneja_stock && $producto->stock !== null) {
                    $producto->stock = (int) $producto->stock + (int) $detalle->cantidad;
                    $producto->save();
                }
            }

            $orden->delete();
            DB::commit();

            return response()->json([
                'message' => 'Orden eliminada exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
