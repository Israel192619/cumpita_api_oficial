<?php

namespace App\Http\Controllers;

use App\Events\PreordenActualizadaEvent;
use App\Events\StockActualizadoEvent;
use App\Models\Cliente;
use App\Models\ModificadorOpcion;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\Producto;
use App\Models\ReservaStock;
use App\Models\ReservaStockModificador;
use App\Events\ReservaStockActualizadaEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SolicitudPreordenController extends Controller
{
    private const MINUTOS_RESERVA = 20;

    public function catalogo()
    {
        $productos = Producto::with(['categoria:id,nombre', 'estacion:id,nombre,activa', 'opciones.modificador', 'configuracionesModificador'])
            ->where('activo', true)
            ->whereHas('estacion', fn ($query) => $query->where('activa', true))
            ->orderBy('nombre')->get()
            ->map(function (Producto $producto) {
                $reservado = (int) ReservaStock::activas()->where('producto_id', $producto->id)->sum('cantidad');
                $producto->stock_disponible = $producto->maneja_stock && $producto->stock !== null ? max(0, $producto->stock - $reservado) : null;
                $producto->modificadores = collect($producto->modificadores_estructurados)->map(function ($grupo) {
                    $grupo['opciones'] = collect($grupo['opciones'] ?? [])->map(function ($opcion) {
                        $reservado = (int) ReservaStockModificador::activas()->where('modificador_opcion_id', $opcion['id'])->sum('cantidad');
                        if ($opcion['maneja_stock'] && $opcion['stock'] !== null) $opcion['stock_disponible'] = max(0, (int) $opcion['stock'] - $reservado);
                        return $opcion;
                    })->filter(fn ($opcion) => $opcion['activo'] && (!$opcion['maneja_stock'] || $opcion['stock_disponible'] === null || $opcion['stock_disponible'] > 0))->values();
                    return $grupo;
                })->values();
                unset($producto->opciones, $producto->configuracionesModificador, $producto->estacion);
                return $producto->only(['id', 'categoria_id', 'categoria', 'nombre', 'descripcion', 'precio', 'imagen_url', 'maneja_stock', 'stock_disponible', 'modificadores']);
            })->filter(fn ($producto) => !$producto['maneja_stock'] || $producto['stock_disponible'] === null || $producto['stock_disponible'] > 0)->values();

        return response()->json(['productos' => $productos]);
    }

    public function clientePorTelefono(Request $request)
    {
        $telefono = $this->normalizarTelefono((string) $request->query('telefono'));
        if (strlen($telefono) !== 8) return response()->json(['cliente' => null]);
        $cliente = Cliente::where('telefono_normalizado', $telefono)->first()
            ?? Cliente::whereNotNull('telefono')->get()->first(fn (Cliente $item) => $this->normalizarTelefono($item->telefono) === $telefono);
        return response()->json(['cliente' => $cliente ? ['nombre' => $cliente->nombre] : null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_nombre' => ['required', 'string', 'max:120'],
            'cliente_telefono' => ['required', 'string', 'regex:/^\d{8}$/'],
            'tipo_orden' => ['required', 'in:dine-in,to-go'],
            'fecha_programada' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.modificador_opcion_ids' => ['nullable', 'array'],
            'items.*.modificador_opcion_ids.*' => ['integer', 'exists:modificador_opciones,id'],
            'items.*.nota' => ['nullable', 'string', 'max:255'],
        ]);
        $fechaProgramada = Carbon::parse($data['fecha_programada']);
        abort_unless($fechaProgramada->isSameDay(now()), 422, 'La solicitud solamente puede registrarse para hoy.');
        abort_if($fechaProgramada->lt(now()->addMinutes(20)), 422, 'La hora de llegada debe tener al menos 20 minutos de anticipación.');
        $telefono = $this->normalizarTelefono($data['cliente_telefono']);
        abort_if(strlen($telefono) !== 8, 422, 'Ingresa un teléfono de 8 dígitos, usando solamente números.');

        $orden = DB::transaction(function () use ($data, $telefono) {
            $ids = collect($data['items'])->pluck('producto_id')->unique();
            $productos = Producto::with(['estacion', 'opciones.modificador', 'configuracionesModificador'])
                ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $opcionIds = collect($data['items'])->flatMap(fn ($item) => $item['modificador_opcion_ids'] ?? [])->unique();
            $opciones = ModificadorOpcion::whereIn('id', $opcionIds)->get()->keyBy('id');
            $cantidadesProducto = collect($data['items'])->groupBy('producto_id')->map(fn ($items) => $items->sum('cantidad'));
            $cantidadesOpcion = collect($data['items'])->flatMap(fn ($item) => collect($item['modificador_opcion_ids'] ?? [])->map(fn ($id) => ['id' => $id, 'cantidad' => $item['cantidad']]))->groupBy('id')->map(fn ($items) => $items->sum('cantidad'));
            $subtotal = 0;

            foreach ($data['items'] as $item) {
                $producto = $productos->get($item['producto_id']);
                abort_unless($producto?->activo && $producto->estacion?->activa, 422, 'Uno de los productos ya no está disponible.');
                $elegidas = collect($item['modificador_opcion_ids'] ?? [])->map(fn ($id) => (int) $id);
                $permitidas = $producto->opciones->where('activo', true)->pluck('id');
                abort_if($elegidas->diff($permitidas)->isNotEmpty(), 422, 'Una opción elegida no pertenece al producto.');
                $this->validarSeleccion($producto, $elegidas);
                if ($producto->maneja_stock && $producto->stock !== null) {
                    $reservado = (int) ReservaStock::activas()->where('producto_id', $producto->id)->sum('cantidad');
                    abort_if((int) $cantidadesProducto[$producto->id] > $producto->stock - $reservado, 422, "Ya no hay stock suficiente de {$producto->nombre}.");
                }
                foreach ($elegidas as $opcionId) {
                    $opcion = $opciones->get($opcionId);
                    if ($opcion?->maneja_stock && $opcion->stock !== null) {
                        $reservado = (int) ReservaStockModificador::activas()->where('modificador_opcion_id', $opcionId)->sum('cantidad');
                        abort_if((int) $cantidadesOpcion[$opcionId] > $opcion->stock - $reservado, 422, "Ya no hay stock suficiente de {$opcion->nombre}.");
                    }
                }
                $extra = $elegidas->sum(fn ($id) => (float) ($opciones->get($id)?->precio_extra ?? 0));
                $subtotal += ((float) $producto->precio + $extra) * (int) $item['cantidad'];
            }

            $cliente = Cliente::where('telefono_normalizado', $telefono)->first()
                ?? Cliente::whereNotNull('telefono')->get()->first(fn (Cliente $item) => $this->normalizarTelefono($item->telefono) === $telefono);
            if (!$cliente) {
                $cliente = Cliente::create(['nombre' => trim($data['cliente_nombre']), 'telefono' => trim($data['cliente_telefono']), 'telefono_normalizado' => $telefono]);
            } else {
                $cliente->update(['nombre' => trim($data['cliente_nombre']), 'telefono' => trim($data['cliente_telefono']), 'telefono_normalizado' => $telefono]);
            }

            $numero = Orden::siguienteNumeroParaFecha(now()->toDateString());
            $orden = Orden::create([
                'user_id' => null,
                'cliente_id' => $cliente->id,
                'numero_orden' => $numero,
                'fecha_programada' => $data['fecha_programada'],
                'tipo_flujo' => 'preorden',
                'origen_registro' => 'cliente',
                'estado_solicitud' => 'pendiente',
                'codigo_publico' => (string) Str::uuid(),
                'solicitud_expira_en' => now()->addMinutes(self::MINUTOS_RESERVA),
                'estado_preorden' => 'programada',
                'subtotal' => $subtotal,
                'descuento' => 0,
                'total' => $subtotal,
                'estado' => 'pendiente',
                'estado_pago' => 'pendiente',
                'observaciones' => $data['observaciones'] ?? null,
                'tipo_orden' => $data['tipo_orden'],
            ]);

            foreach ($data['items'] as $item) {
                $producto = $productos->get($item['producto_id']);
                foreach (range(1, (int) $item['cantidad']) as $_) {
                    $detalle = OrdenDetalle::create(['orden_id' => $orden->id, 'producto_id' => $producto->id, 'estacion_id' => $producto->estacion_id, 'cantidad' => 1, 'precio_unitario' => $producto->precio, 'nota' => $item['nota'] ?? null]);
                    foreach ($item['modificador_opcion_ids'] ?? [] as $id) {
                        OrdenDetalleOpcion::create(['orden_detalle_id' => $detalle->id, 'modificador_opcion_id' => $id, 'precio_extra' => $opciones->get($id)->precio_extra]);
                    }
                }
                if ($producto->maneja_stock && $producto->stock !== null) {
                    $reserva = ReservaStock::firstOrNew(['producto_id' => $producto->id, 'sesion_id' => $orden->codigo_publico]);
                    $reserva->fill(['usuario_id' => null, 'cantidad' => (int) ($reserva->cantidad ?? 0) + (int) $item['cantidad'], 'expira_en' => $orden->solicitud_expira_en])->save();
                }
                foreach ($item['modificador_opcion_ids'] ?? [] as $opcionId) {
                    $opcion = $opciones->get($opcionId);
                    if ($opcion?->maneja_stock && $opcion->stock !== null) {
                        $reserva = ReservaStockModificador::firstOrNew(['modificador_opcion_id' => $opcionId, 'sesion_id' => $orden->codigo_publico]);
                        $reserva->fill(['usuario_id' => null, 'cantidad' => (int) ($reserva->cantidad ?? 0) + (int) $item['cantidad'], 'expira_en' => $orden->solicitud_expira_en])->save();
                    }
                }
            }
            return $orden;
        });

        ReservaStockActualizadaEvent::dispatch($orden->detalles->pluck('producto_id')->unique()->all(), $orden->detalles->flatMap->opciones->pluck('modificador_opcion_id')->unique()->all());
        event(new PreordenActualizadaEvent($orden, 'solicitud_creada'));
        return response()->json(['message' => 'Tu solicitud fue enviada para revisión.', 'codigo' => $orden->codigo_publico, 'estado' => $orden->estado_solicitud], 201);
    }

    public function show(string $codigo)
    {
        $this->vencerSolicitudes();
        $orden = Orden::with(['cliente:id,nombre,telefono', 'detalles.producto:id,nombre', 'detalles.opciones.modificadorOpcion:id,nombre'])
            ->where('codigo_publico', $codigo)->where('origen_registro', 'cliente')->firstOrFail();
        return response()->json(['solicitud' => $this->resumen($orden)]);
    }

    public function index()
    {
        $this->vencerSolicitudes();
        $base = Orden::with(['cliente:id,nombre,telefono', 'detalles.producto:id,nombre', 'detalles.opciones.modificadorOpcion:id,nombre'])->where('origen_registro', 'cliente');
        $ordenes = (clone $base)->where('estado_solicitud', 'pendiente')->orderBy('fecha_programada')->get();
        $vencidas = (clone $base)->where('estado_solicitud', 'vencida')->latest('solicitud_expira_en')->limit(30)->get();
        return response()->json(['solicitudes' => $ordenes->map(fn ($orden) => $this->resumen($orden)), 'vencidas' => $vencidas->map(fn ($orden) => $this->resumen($orden))]);
    }

    public function reactivar(Orden $orden)
    {
        $orden = DB::transaction(function () use ($orden) {
            $orden = Orden::with(['detalles.producto', 'detalles.opciones.modificadorOpcion'])->lockForUpdate()->findOrFail($orden->id);
            abort_unless($orden->origen_registro === 'cliente' && $orden->estado_solicitud === 'vencida', 409, 'La solicitud no está vencida.');
            abort_if($orden->fecha_programada?->lt(now()->addMinutes(20)), 422, 'La hora solicitada ya no permite preparar el pedido con 20 minutos de anticipación.');
            $productos = $orden->detalles->groupBy('producto_id');
            foreach ($productos as $productoId => $detalles) {
                $producto = Producto::lockForUpdate()->findOrFail($productoId); $cantidad = $detalles->sum('cantidad');
                $reservado = (int) ReservaStock::activas()->where('producto_id', $productoId)->sum('cantidad');
                if ($producto->maneja_stock && $producto->stock !== null) abort_if($cantidad > $producto->stock - $reservado, 422, "No hay stock suficiente de {$producto->nombre}.");
            }
            $opciones = $orden->detalles->flatMap->opciones->groupBy('modificador_opcion_id');
            foreach ($opciones as $opcionId => $usos) {
                $opcion = ModificadorOpcion::lockForUpdate()->findOrFail($opcionId); $reservado = (int) ReservaStockModificador::activas()->where('modificador_opcion_id', $opcionId)->sum('cantidad');
                if ($opcion->maneja_stock && $opcion->stock !== null) abort_if($usos->count() > $opcion->stock - $reservado, 422, "No hay stock suficiente de {$opcion->nombre}.");
            }
            $expira = now()->addMinutes(self::MINUTOS_RESERVA);
            foreach ($productos as $productoId => $detalles) { $producto = $detalles->first()->producto; if ($producto->maneja_stock && $producto->stock !== null) ReservaStock::create(['producto_id' => $productoId, 'usuario_id' => null, 'sesion_id' => $orden->codigo_publico, 'cantidad' => $detalles->sum('cantidad'), 'expira_en' => $expira]); }
            foreach ($opciones as $opcionId => $usos) { $opcion = $usos->first()->modificadorOpcion; if ($opcion->maneja_stock && $opcion->stock !== null) ReservaStockModificador::create(['modificador_opcion_id' => $opcionId, 'usuario_id' => null, 'sesion_id' => $orden->codigo_publico, 'cantidad' => $usos->count(), 'expira_en' => $expira]); }
            $orden->update(['estado_solicitud' => 'pendiente', 'estado_preorden' => 'programada', 'estado' => 'pendiente', 'solicitud_expira_en' => $expira]);
            return $orden;
        });
        ReservaStockActualizadaEvent::dispatch($orden->detalles->pluck('producto_id')->unique()->all(), $orden->detalles->flatMap->opciones->pluck('modificador_opcion_id')->unique()->all());
        event(new PreordenActualizadaEvent($orden, 'solicitud_reactivada'));
        return response()->json(['message' => 'Solicitud reactivada y stock reservado durante 20 minutos.']);
    }

    public function disponibilidad(Orden $orden)
    {
        abort_unless($orden->origen_registro === 'cliente' && in_array($orden->estado_solicitud, ['pendiente', 'vencida'], true), 404);
        $orden->load(['detalles.producto', 'detalles.opciones.modificadorOpcion']);
        $faltantes = [];
        foreach ($orden->detalles->groupBy('producto_id') as $detalles) {
            $producto = $detalles->first()->producto; $necesarias = (int) $detalles->sum('cantidad');
            if ($producto?->maneja_stock && $producto->stock !== null) {
                $reservado = (int) ReservaStock::activas()->where('producto_id', $producto->id)->where('sesion_id', '!=', $orden->codigo_publico)->sum('cantidad');
                $disponibles = max(0, (int) $producto->stock - $reservado);
                if ($necesarias > $disponibles) $faltantes[] = ['tipo' => 'producto', 'nombre' => $producto->nombre, 'necesarias' => $necesarias, 'disponibles' => $disponibles, 'faltan' => $necesarias - $disponibles];
            }
        }
        $usosOpciones = [];
        foreach ($orden->detalles as $detalle) foreach ($detalle->opciones as $uso) {
            $id = (int) $uso->modificador_opcion_id;
            if (!isset($usosOpciones[$id])) $usosOpciones[$id] = ['opcion' => $uso->modificadorOpcion, 'cantidad' => 0];
            $usosOpciones[$id]['cantidad'] += (int) $detalle->cantidad;
        }
        foreach ($usosOpciones as $uso) {
            $opcion = $uso['opcion']; $necesarias = $uso['cantidad'];
            if ($opcion?->maneja_stock && $opcion->stock !== null) {
                $reservado = (int) ReservaStockModificador::activas()->where('modificador_opcion_id', $opcion->id)->where('sesion_id', '!=', $orden->codigo_publico)->sum('cantidad');
                $disponibles = max(0, (int) $opcion->stock - $reservado);
                if ($necesarias > $disponibles) $faltantes[] = ['tipo' => 'modificador', 'nombre' => $opcion->nombre, 'necesarias' => $necesarias, 'disponibles' => $disponibles, 'faltan' => $necesarias - $disponibles];
            }
        }
        $horaMinima = now()->addMinutes(20);
        if ($horaMinima->second > 0 || $horaMinima->micro > 0) $horaMinima->addMinute()->startOfMinute();
        return response()->json([
            'disponible' => empty($faltantes) && $orden->fecha_programada?->gte($horaMinima),
            'stock_disponible' => empty($faltantes),
            'hora_valida' => (bool) $orden->fecha_programada?->gte($horaMinima),
            'hora_minima' => $horaMinima->toIso8601String(),
            'faltantes' => $faltantes,
        ]);
    }

    public function aceptar(Orden $orden)
    {
        $orden = DB::transaction(function () use ($orden) {
            $orden = Orden::with(['detalles.producto', 'detalles.opciones.modificadorOpcion'])->lockForUpdate()->findOrFail($orden->id);
            abort_unless($orden->origen_registro === 'cliente' && $orden->estado_solicitud === 'pendiente', 409, 'La solicitud ya fue revisada.');
            abort_if($orden->solicitud_expira_en?->isPast(), 422, 'La reserva de stock venció. La solicitud ya no puede confirmarse.');
            abort_if($orden->fecha_programada?->isPast(), 422, 'La fecha programada ya pasó. Rechaza la solicitud o coordina otra hora con el cliente.');
            foreach ($orden->detalles as $detalle) {
                $producto = Producto::lockForUpdate()->findOrFail($detalle->producto_id);
                abort_unless($producto->activo, 422, "{$producto->nombre} ya no está disponible.");
                if ($producto->maneja_stock && $producto->stock !== null) {
                    abort_if($producto->stock < $detalle->cantidad, 422, "No hay stock suficiente de {$producto->nombre}.");
                    $producto->decrement('stock', $detalle->cantidad);
                }
                foreach ($detalle->opciones as $detalleOpcion) {
                    $opcion = ModificadorOpcion::lockForUpdate()->findOrFail($detalleOpcion->modificador_opcion_id);
                    if ($opcion->maneja_stock && $opcion->stock !== null) {
                        abort_if($opcion->stock < $detalle->cantidad, 422, "No hay stock suficiente de {$opcion->nombre}.");
                        $opcion->decrement('stock', $detalle->cantidad);
                    }
                }
            }
            $orden->update(['user_id' => auth('api')->id(), 'estado_solicitud' => 'aceptada', 'solicitud_revisada_por' => auth('api')->id(), 'solicitud_revisada_en' => now(), 'motivo_rechazo' => null]);
            ReservaStock::where('sesion_id', $orden->codigo_publico)->delete();
            ReservaStockModificador::where('sesion_id', $orden->codigo_publico)->delete();
            foreach ($orden->detalles as $detalle) app(\App\Services\KdsEstacionService::class)->sincronizarDetalle($detalle->fresh());
            return $orden;
        });
        foreach ($orden->detalles->pluck('producto_id')->unique() as $productoId) {
            $producto = Producto::find($productoId);
            if ($producto?->maneja_stock && $producto->stock !== null) event(new StockActualizadoEvent($producto->id, (int) $producto->stock));
        }
        event(new PreordenActualizadaEvent($orden, 'solicitud_aceptada'));
        return response()->json(['message' => 'Solicitud aceptada como preorden programada.']);
    }

    public function rechazar(Request $request, Orden $orden)
    {
        $data = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        $orden = DB::transaction(function () use ($orden, $data) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            abort_unless($orden->origen_registro === 'cliente' && $orden->estado_solicitud === 'pendiente', 409, 'La solicitud ya fue revisada.');
            $orden->update(['estado_solicitud' => 'rechazada', 'estado_preorden' => 'cancelada', 'estado' => 'cancelado', 'solicitud_revisada_por' => auth('api')->id(), 'solicitud_revisada_en' => now(), 'motivo_rechazo' => $data['motivo'] ?? null]);
            ReservaStock::where('sesion_id', $orden->codigo_publico)->delete();
            ReservaStockModificador::where('sesion_id', $orden->codigo_publico)->delete();
            return $orden;
        });
        event(new PreordenActualizadaEvent($orden, 'solicitud_rechazada'));
        ReservaStockActualizadaEvent::dispatch($orden->detalles()->pluck('producto_id')->unique()->values()->all(), $orden->detalles()->with('opciones')->get()->flatMap->opciones->pluck('modificador_opcion_id')->unique()->values()->all());
        return response()->json(['message' => 'Solicitud rechazada.']);
    }

    private function validarSeleccion(Producto $producto, $elegidas): void
    {
        foreach ($producto->opciones->groupBy('modificador_id') as $opciones) {
            $modificador = $opciones->first()->modificador;
            if (!$modificador?->activo) continue;
            $idsActivos = $opciones->where('activo', true)->pluck('id');
            $cantidad = $elegidas->filter(fn ($id) => $idsActivos->contains($id))->count();
            $requerida = $producto->configuracionesModificador->firstWhere('modificador_id', $modificador->id)?->cantidad_requerida;
            $esMaxima = $modificador->usaLimiteMaximo();
            abort_if($requerida !== null && $esMaxima && $cantidad > (int) $requerida, 422, "Puedes elegir hasta {$requerida} en {$modificador->nombre}.");
            abort_if($requerida !== null && !$esMaxima && $cantidad !== (int) $requerida, 422, "Debes elegir exactamente {$requerida} en {$modificador->nombre}.");
            abort_if($requerida === null && $modificador->requerido && $cantidad === 0, 422, "Debes elegir una opción en {$modificador->nombre}.");
            abort_if($requerida === null && $modificador->tipo === 'unico' && $cantidad > 1, 422, "Solo puedes elegir una opción en {$modificador->nombre}.");
        }
    }

    private function normalizarTelefono(string $telefono): string
    {
        return preg_replace('/\D+/', '', $telefono) ?? '';
    }

    private function vencerSolicitudes(): void
    {
        $vencidas = Orden::where('origen_registro', 'cliente')->where('estado_solicitud', 'pendiente')->where('solicitud_expira_en', '<=', now())->get();
        foreach ($vencidas as $orden) {
            $orden->update(['estado_solicitud' => 'vencida', 'estado_preorden' => 'cancelada', 'estado' => 'cancelado']);
            ReservaStock::where('sesion_id', $orden->codigo_publico)->delete();
            ReservaStockModificador::where('sesion_id', $orden->codigo_publico)->delete();
        }
        if ($vencidas->isNotEmpty()) ReservaStockActualizadaEvent::dispatch([], []);
    }

    private function resumen(Orden $orden): array
    {
        return ['id' => $orden->id, 'codigo' => $orden->codigo_publico, 'estado' => $orden->estado_solicitud, 'expira_en' => $orden->solicitud_expira_en, 'cliente' => $orden->cliente?->nombre, 'telefono' => $orden->cliente?->telefono, 'tipo_orden' => $orden->tipo_orden, 'fecha_programada' => $orden->fecha_programada, 'observaciones' => $orden->observaciones, 'total' => $orden->total, 'motivo_rechazo' => $orden->motivo_rechazo, 'items' => $orden->detalles->map(fn ($detalle) => ['producto' => $detalle->producto?->nombre, 'cantidad' => $detalle->cantidad, 'nota' => $detalle->nota, 'opciones' => $detalle->opciones->map(fn ($opcion) => $opcion->modificadorOpcion?->nombre)->filter()->values()])->values()];
    }
}
