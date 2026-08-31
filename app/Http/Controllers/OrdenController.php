<?php

namespace App\Http\Controllers;

use App\Events\OrdenCreadaEvent;
use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\PreordenActualizadaEvent;
use App\Events\CajaActualizadaEvent;
use App\Events\StockActualizadoEvent;
use App\Models\HistorialCambioOrden;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\PagoOrden;
use App\Models\Cliente;
use App\Models\Caja;
use App\Models\Producto;
use App\Models\ReservaStock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class OrdenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $ordenes = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto', 'detalles.estacion', 'detalles.opciones.modificadorOpcion', 'preordenActivadaPor')
            ->withMax('cambiosMesero as ultimo_cambio_mesero_en', 'created_at')
            ->when($request->filled('tipo_flujo'), fn ($query) => $query->where('tipo_flujo', $request->input('tipo_flujo')))
            ->when($request->filled('estado_preorden'), fn ($query) => $query->where('estado_preorden', $request->input('estado_preorden')))
            ->orderByDesc('created_at')->get();
        return response()->json([
            'ordenes' => $ordenes
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     * Soporta creación desde POS con cliente_nombre y cliente_telefono
     */
    public function store(Request $request)
    {
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
            'observaciones' => 'nullable|string',
            'reserva_sesion_id' => 'nullable|uuid',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if ($this->esMesero()) {
            abort_unless($request->input('tipo_flujo') === 'preorden', 403, 'El mesero solamente puede registrar preórdenes.');
        }
        if (!$request->cliente_id && !$request->cliente_nombre) {
            return response()->json(['message' => 'El cliente es obligatorio para crear una orden.'], 422);
        }
        $productos = Producto::with('estacion')
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
        }
        DB::beginTransaction();
        try {
            // Bloqueamos el stock en un orden estable: reserva y venta no pueden
            // confirmar las últimas unidades al mismo tiempo.
            $productos = Producto::with('estacion')->whereIn('id', collect($request->items)->pluck('producto_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $userActual = auth('api')->user();
            $clienteId = null;
            if ($request->cliente_id) {
                $clienteId = $request->cliente_id;
            }
            elseif ($request->cliente_nombre) {
                $cliente = Cliente::firstOrCreate(
                    ['nombre' => $request->cliente_nombre],
                    ['telefono' => $request->cliente_telefono]
                );
                $clienteId = $cliente->id;
            }

            $tipoFlujo = $request->input('tipo_flujo', $request->filled('fecha_programada') ? 'preorden' : 'normal');
            $fechaOrden = $request->filled('fecha_orden')
                ? Carbon::createFromFormat('Y-m-d\TH:i:s', $request->fecha_orden)
                : now();

            $ultimoNumero = Orden::whereDate(
                'fecha_orden',
                $fechaOrden->toDateString()
            )->max('numero_orden');

            $numeroOrden = ($ultimoNumero ?? 0) + 1;

            // Crear la orden
            $orden = Orden::create([
                'user_id' => $userActual->id,
                'cliente_id' => $clienteId,
                'mesa_id' => $request->mesa_id,
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

                // Cada unidad es un detalle independiente para que Cocina, Parrilla y Servicio
                // puedan finalizarla sin afectar a las demás unidades del mismo producto.
                for ($unidad = 0; $unidad < (int) $item['cantidad']; $unidad++) {
                    $ordenDetalle = OrdenDetalle::create([
                        'orden_id' => $orden->id,
                        'producto_id' => $item['producto_id'],
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
                }
            }

            if ($request->filled('reserva_sesion_id')) {
                ReservaStock::where('sesion_id', $request->reserva_sesion_id)->delete();
            }

            DB::commit();

            if ($orden->esPreordenProgramada()) {
                $this->emitirEventoSeguro(new PreordenActualizadaEvent($orden, 'preorden_creada'), 'preorden_creada', $orden->id);
            } else {
                $this->emitirEventoSeguro(new OrdenCreadaEvent($orden), 'orden_creada', $orden->id);
            }

            return response()->json([
                'message' => 'Orden creada exitosamente',
                'orden' => $orden->load('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.estacion', 'detalles.opciones.modificadorOpcion')
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $orden = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto', 'detalles.estacion', 'detalles.opciones.modificadorOpcion')->findOrFail($id);
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
            'observaciones' => 'nullable|string',
            'expected_version' => 'required|integer|min:1',
            'items' => 'nullable|array|min:1',
            'items.*.producto_id' => 'required_with:items|exists:productos,id',
            'items.*.orden_detalle_id' => 'nullable|integer',
            'items.*.cantidad' => 'required_with:items|integer|min:1',
            'items.*.precio_unitario' => 'required_with:items|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
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
                abort_if((int) $orden->version !== (int) $request->expected_version, 409,
                    'Esta orden fue modificada por otro cajero. Actualízala antes de guardar para no perder cambios.');
                abort_if($orden->estado === 'cancelado', 422,
                    'Una orden cancelada no puede editarse. Crea una nueva orden si el cliente vuelve a pedir.');
                $this->autorizarMeseroSobrePreorden($orden);
                $usuarioId = auth('api')->id();
                $estadoAnterior = $orden->estado;

                $clienteId = $orden->cliente_id;
                if ($request->has('cliente_id')) {
                    $clienteId = $request->cliente_id;
                } elseif ($request->filled('cliente_nombre')) {
                    $cliente = Cliente::firstOrCreate(
                        ['nombre' => $request->cliente_nombre],
                        ['telefono' => $request->cliente_telefono]
                    );
                    $clienteId = $cliente->id;
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
                if ($request->has('cliente_id') || $request->filled('cliente_nombre')) {
                    $updateData['cliente_id'] = $clienteId;
                }
                if ($request->has('fecha_orden')) {
                    $updateData['fecha_orden'] = $request->filled('fecha_orden') ? $request->fecha_orden : null;
                }
                if ($request->has('tipo_flujo') || $request->has('fecha_programada')) {
                    abort_if($orden->estado_preorden === 'cancelada', 422, 'Una preorden cancelada no puede modificarse.');
                    $tipoFlujo = $request->input('tipo_flujo', $request->filled('fecha_programada') ? 'preorden' : 'normal');
                    if ($orden->estado_preorden === 'activada' && $tipoFlujo !== 'preorden') {
                        abort(422, 'Una preorden activada no puede convertirse en pedido normal.');
                    }
                    if ($tipoFlujo === 'preorden' && $orden->estado_preorden !== 'activada') {
                        abort_unless($request->filled('fecha_programada'), 422, 'La fecha programada es obligatoria para una preorden.');
                        abort_unless(Carbon::createFromFormat('Y-m-d\TH:i:s', $request->fecha_programada)->isFuture(), 422, 'La fecha programada debe ser futura.');
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
                    $huboUnidadesNuevas = $this->sincronizarDetallesOrden($orden, $request->items, $usuarioId, $historialIds);
                    if ($huboUnidadesNuevas && $orden->estado === 'listo') {
                        // Una unidad adicional vuelve a abrir trabajo sin tocar los estados
                        // de las unidades que Cocina/Parrilla ya finalizaron.
                        $orden->update(['estado' => 'preparando']);
                    }
                }

                $pagosTotales = PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado');
                $orden->estado_pago = $pagosTotales <= 0
                    ? 'pendiente'
                    : ($pagosTotales < (float) $orden->total ? 'parcial' : 'completado');
                $orden->version = (int) $orden->version + 1;
                $orden->save();

                return $orden->fresh([
                    'user', 'cliente', 'mesa', 'pagos', 'detalles.producto.categoria', 'detalles.estacion',
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
            if (str_contains($e->getMessage(), 'No hay suficiente stock') || str_contains($e->getMessage(), 'estación de trabajo activa')) {
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
                $cajaId = null;

                if ($pagado > 0) {
                    abort_unless($request->filled('metodo_pago'), 422,
                        'Selecciona el método con el que se realizará la devolución.');

                    if ($request->metodo_pago === 'efectivo') {
                        $usuarioId = auth('api')->id();
                        $caja = Caja::where(function ($query) use ($usuarioId) {
                                $query->where('user_id', $usuarioId)
                                    ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
                            })
                            ->where('estado', 'abierta')
                            ->lockForUpdate()
                            ->first();

                        abort_unless($caja, 422, 'No tienes acceso a una caja abierta para devolver efectivo.');
                        $cajaId = $caja->id;
                    }
                }

                $stocksRepuestos = [];
                foreach (OrdenDetalle::with('producto')->where('orden_id', $orden->id)->get() as $detalle) {
                    $producto = Producto::lockForUpdate()->find($detalle->producto_id);
                    if ($producto && $producto->maneja_stock && $producto->stock !== null) {
                        $producto->increment('stock', (int) $detalle->cantidad);
                        $stocksRepuestos[$producto->id] = (int) $producto->fresh()->stock;
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

    public function activarPreorden(string $id)
    {
        try {
            $orden = DB::transaction(function () use ($id) {
                $orden = Orden::with('detalles')->lockForUpdate()->findOrFail($id);
                abort_unless($orden->tipo_flujo === 'preorden', 422, 'La orden seleccionada no es una preorden.');
                abort_if($orden->estado_preorden === 'activada', 409, 'La preorden ya fue activada.');
                abort_if($orden->estado_preorden === 'cancelada', 422, 'Una preorden cancelada no puede activarse.');

                $orden->update([
                    'estado_preorden' => 'activada',
                    'preorden_activada_en' => now(),
                    'preorden_activada_por' => auth('api')->id(),
                    'fecha_orden' => now(),
                    'estado' => 'pendiente',
                ]);
                app(\App\Services\KdsEstacionService::class)->sincronizar($orden->detalles);
                return $orden->fresh(['cliente', 'mesa', 'detalles.producto', 'detalles.estadosEstacion']);
            });

            $this->emitirEventoSeguro(new PreordenActualizadaEvent($orden, 'preorden_activada'), 'preorden_activada', $orden->id);
            $this->emitirEventoSeguro(new OrdenCreadaEvent($orden), 'orden_creada', $orden->id);

            return response()->json(['message' => 'Preorden activada correctamente.', 'orden' => $orden]);
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
        } catch (\Throwable $e) {
            Log::warning('No se pudo publicar la notificación en tiempo real de la orden.', [
                'tipo' => $tipo,
                'orden_id' => $ordenId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Compara los detalles persistidos con el payload del POS sin recrearlos. */
    private function sincronizarDetallesOrden(Orden $orden, array $items, ?int $usuarioId, array &$historialIds): bool
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
            ->with(['producto', 'estacion', 'opciones'])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $idsRecibidos = [];
        $huboUnidadesNuevas = false;

        foreach ($items as $item) {
            $detalleId = $item['orden_detalle_id'] ?? null;
            $productoNuevo = Producto::with('estacion')->findOrFail($item['producto_id']);
            $this->asegurarEstacionActiva($productoNuevo);
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

                if ($detalle->producto_id !== $productoNuevo->id) {
                    $this->ajustarStock($productoAnterior, -(int) $detalle->cantidad);
                    $this->ajustarStock($productoNuevo, $cantidadNueva);
                } else {
                    $this->ajustarStock($productoNuevo, $cantidadNueva - (int) $detalle->cantidad);
                }

                $cambioCantidad = (int) $detalle->cantidad !== $cantidadNueva;
                $cambioBase = $cambioCantidad
                    || $detalle->producto_id !== $productoNuevo->id
                    || (float) $detalle->precio_unitario !== (float) $item['precio_unitario']
                    || ($detalle->nota ?? null) !== ($item['nota'] ?? null);

                $detalle->fill([
                    'producto_id' => $productoNuevo->id,
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

            $this->ajustarStock($productoNuevo, $cantidadNueva);
            $detalle = OrdenDetalle::create([
                'orden_id' => $orden->id,
                'producto_id' => $productoNuevo->id,
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

        $detallesExistentes
            ->filter(fn (OrdenDetalle $detalle) => !isset($idsRecibidos[$detalle->id]))
            ->each(function (OrdenDetalle $detalle) use ($orden, $usuarioId, &$historialIds) {
                $this->ajustarStock($detalle->producto, -(int) $detalle->cantidad);
                $this->registrarCambio(
                    $orden, $detalle, $detalle->producto, 'detalle_eliminado', (int) $detalle->cantidad,
                    null, $this->datosDetalle($detalle), null, $usuarioId, $historialIds,
                );
                $detalle->opciones()->delete();
                $detalle->delete();
            });

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

    private function datosDetalle(OrdenDetalle $detalle): array
    {
        return [
            'detalle_id' => $detalle->id,
            'producto_id' => $detalle->producto_id,
            'producto_nombre' => $detalle->producto?->nombre,
            'estacion_id' => $detalle->estacion_id,
            'estacion_nombre' => $detalle->estacion?->nombre,
            'cantidad' => (int) $detalle->cantidad,
            'precio_unitario' => (float) $detalle->precio_unitario,
            'nota' => $detalle->nota,
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

    private function autorizarMeseroSobrePreorden(Orden $orden): void
    {
        if (!$this->esMesero()) return;
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
