<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Events\ServicioFichaActualizadaEvent;
use App\Http\Requests\AgregarAdicionalOrdenRequest;
use App\Models\HistorialCambioOrden;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\PagoOrden;
use App\Models\Producto;
use App\Models\ModificadorOpcion;
use App\Models\ReservaStock;
use App\Models\ReservaStockModificador;
use App\Events\ReservaStockActualizadaEvent;
use App\Services\KdsEstacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrdenAdicionalController extends Controller
{
    public function buscar(Request $request)
    {
        $this->asegurarMesero();
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $termino = trim($data['q']);

        $ordenes = Orden::with(['cliente:id,nombre', 'mesa:id,numero'])
            ->deFechaOperativa(now()->toDateString())
            ->where(function ($query) use ($termino) {
                $query->where('numero_orden', 'like', "%{$termino}%")
                    ->orWhereHas('cliente', fn ($cliente) => $cliente->where('nombre', 'like', "%{$termino}%"))
                    ->orWhereHas('mesa', fn ($mesa) => $mesa->where('numero', 'like', "%{$termino}%"));
            })
            ->latest()->limit(15)->get()
            ->map(fn (Orden $orden) => $this->resumen($orden));

        return response()->json(['ordenes' => $ordenes]);
    }

    public function show(Orden $orden)
    {
        $this->asegurarMesero();
        return response()->json(['orden' => $this->detalle($orden)]);
    }

    public function store(
        AgregarAdicionalOrdenRequest $request,
        Orden $orden,
        KdsEstacionService $kds,
    ) {
        $usuarioId = (int) auth('api')->id();
        $resultado = DB::transaction(function () use ($request, $orden, $kds, $usuarioId) {
            $orden = Orden::lockForUpdate()->findOrFail($orden->id);
            $this->asegurarModificable($orden);

            $producto = Producto::with(['estacion', 'opciones.modificador', 'configuracionesModificador'])->lockForUpdate()
                ->findOrFail($request->integer('producto_id'));
            abort_unless($producto->activo && $producto->estacion_id && $producto->estacion?->activa, 422, 'El producto no está disponible para producción.');

            $cantidad = $request->integer('cantidad');
            $sesionReserva = $request->input('reserva_sesion');
            if ($producto->maneja_stock && $producto->stock !== null) {
                $reservado = ReservaStock::activas()->where('producto_id', $producto->id)
                    ->when($sesionReserva, fn ($query) => $query->where('sesion_id', '!=', $sesionReserva))->sum('cantidad');
                abort_if((int) $producto->stock - $reservado < $cantidad, 422, 'No hay suficiente stock para '.$producto->nombre.'.');
                $producto->decrement('stock', $cantidad);
            }

            // Se conservan repetidos: Ala + Ala consume dos unidades del mismo inventario.
            $idsSeleccionados = collect($request->input('modificador_opcion_ids', []))->map(fn ($id) => (int) $id);
            $opcionesDisponibles = $producto->opciones->filter(fn ($opcion) => $opcion->activo)->keyBy('id');
            abort_if($idsSeleccionados->diff($opcionesDisponibles->keys())->isNotEmpty(), 422, 'Una opción seleccionada no pertenece al producto.');

            foreach ($producto->opciones->filter(fn ($opcion) => $opcion->modificador?->activo)->groupBy('modificador_id') as $grupo) {
                $modificador = $grupo->first()?->modificador;
                $cantidadRequerida = $producto->configuracionesModificador->firstWhere('modificador_id', $modificador?->id)?->cantidad_requerida;
                $cantidadEsMaxima = $modificador?->usaLimiteMaximo() || (bool) $producto->configuracionesModificador->firstWhere('modificador_id', $modificador?->id)?->cantidad_es_maxima;
                $idsActivos = $grupo->filter(fn ($opcion) => $opcion->activo)->pluck('id');
                $cantidadSeleccionada = $idsSeleccionados->filter(fn ($id) => $idsActivos->contains($id))->count();
                abort_if($cantidadRequerida !== null && $cantidadEsMaxima && $cantidadSeleccionada > (int) $cantidadRequerida, 422,
                    'Puedes elegir hasta '.$cantidadRequerida.' en '.$modificador->nombre.'.');
                abort_if($cantidadRequerida !== null && !$cantidadEsMaxima && $cantidadSeleccionada !== (int) $cantidadRequerida, 422,
                    'Debes elegir exactamente '.$cantidadRequerida.' en '.$modificador->nombre.'.');
                abort_if($cantidadRequerida === null && $modificador?->requerido && $cantidadSeleccionada === 0, 422,
                    'Debes elegir una opción de '.$modificador->nombre.'.');
                abort_if($cantidadRequerida === null && $modificador?->tipo === 'unico' && $cantidadSeleccionada > 1, 422,
                    'Solo puedes seleccionar una opción de '.$modificador->nombre.'.');
            }

            $opciones = $idsSeleccionados->map(fn ($id) => $opcionesDisponibles->get($id))->filter();
            $usoOpciones = $idsSeleccionados->countBy()->map(fn ($usos) => $usos * $cantidad);
            $opcionesBloqueadas = ModificadorOpcion::whereIn('id', $usoOpciones->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($usoOpciones as $opcionId => $usos) {
                $opcion = $opcionesBloqueadas->get($opcionId);
                if ($opcion?->maneja_stock && $opcion->stock !== null) {
                    $reservado = ReservaStockModificador::activas()->where('modificador_opcion_id', $opcionId)
                        ->when($sesionReserva, fn ($query) => $query->where('sesion_id', '!=', $sesionReserva))->sum('cantidad');
                    abort_if((int) $opcion->stock - $reservado < $usos, 422, 'No hay suficientes unidades de '.$opcion->nombre.'.');
                    $opcion->decrement('stock', $usos);
                }
            }
            $precioUnitario = (float) $producto->precio;
            $extras = (float) $opciones->sum(fn ($opcion) => (float) $opcion->precio_extra);
            $detalles = collect();

            for ($unidad = 0; $unidad < $cantidad; $unidad++) {
                $detalle = OrdenDetalle::create([
                    'orden_id' => $orden->id,
                    'producto_id' => $producto->id,
                    'estacion_id' => $producto->estacion_id,
                    'cantidad' => 1,
                    'precio_unitario' => $precioUnitario,
                    'nota' => $request->input('nota'),
                    'estado_cocina' => 'pendiente',
                ]);
                foreach ($opciones as $opcion) {
                    OrdenDetalleOpcion::create([
                        'orden_detalle_id' => $detalle->id,
                        'modificador_opcion_id' => $opcion->id,
                        'precio_extra' => $opcion->precio_extra,
                    ]);
                }
                $kds->sincronizarDetalle($detalle->fresh());
                $snapshot = $this->snapshot($detalle->fresh(['producto', 'estacion', 'opciones.modificadorOpcion.modificador']));
                HistorialCambioOrden::create([
                    'orden_id' => $orden->id,
                    'orden_detalle_id' => $detalle->id,
                    'user_id' => $usuarioId,
                    'producto_id' => $producto->id,
                    'tipo_cambio' => 'detalle_agregado',
                    'cantidad_nueva' => 1,
                    'datos_nuevo' => [...$snapshot, 'origen' => 'servicio_mesero'],
                ]);
                $detalles->push($detalle);
            }

            $subtotal = (float) $orden->subtotal + (($precioUnitario + $extras) * $cantidad);
            $total = max(0, $subtotal - (float) $orden->descuento);
            $pagado = (float) PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado');
            $orden->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'delivery_monto_esperado' => null,
                'delivery_cambio_preparado' => false,
                'delivery_cambio_preparado_por' => null,
                'delivery_cambio_preparado_en' => null,
                'estado' => in_array($orden->estado, ['listo', 'entregado'], true) ? 'preparando' : $orden->estado,
                'entregada_en' => null,
                'estado_pago' => $pagado <= 0 ? 'pendiente' : ($pagado < $total ? 'parcial' : 'completado'),
            ]);

            if ($sesionReserva) {
                ReservaStock::where('sesion_id', $sesionReserva)->delete();
                ReservaStockModificador::where('sesion_id', $sesionReserva)->delete();
            }
            ReservaStockActualizadaEvent::dispatch([$producto->id], $usoOpciones->keys()->all());
            return ['orden' => $orden->fresh(), 'detalles' => $detalles];
        });

        try {
            event(new OrdenCocinaActualizadaEvent($resultado['orden']));
            $evento = ServicioFichaActualizadaEvent::desdeOrden($resultado['orden'], 'adicional');
            $evento->actividad = ['user_id' => $usuarioId, 'mensaje' => auth('api')->user()?->name.' agregó productos a tu ficha #'.$resultado['orden']->numero_orden.'.'];
            event($evento);
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar el adicional de la orden.', ['orden_id' => $orden->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Producto agregado a la orden.',
            'orden' => $this->detalle($resultado['orden']),
        ], 201);
    }

    private function asegurarMesero(): void
    {
        abort_unless(mb_strtolower(auth('api')->user()?->role?->nombre ?? '') === 'mesero', 403, 'Esta operación requiere una sesión de Mesero.');
    }

    private function asegurarModificable(Orden $orden): void
    {
        $this->asegurarMesero();
        abort_if($orden->esPreordenProgramada(), 422, 'La preorden está pendiente de activación.');
        abort_unless($orden->tipo_flujo !== 'preorden' || $orden->estado_preorden === 'activada', 422, 'La preorden no está activa.');
        abort_if($orden->estado === 'cancelado', 422, 'La orden ya no admite adicionales.');
    }

    private function resumen(Orden $orden): array
    {
        return ['id' => $orden->id, 'numero_orden' => $orden->numero_orden, 'mesa' => $orden->mesa?->numero,
            'cliente' => $orden->cliente?->nombre, 'tipo_orden' => $orden->tipo_orden, 'estado' => $orden->estado,
            'puede_agregar' => $this->puedeAgregar($orden)];
    }

    private function detalle(Orden $orden): array
    {
        $orden->load(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto:id,nombre,categoria_id', 'detalles.producto.categoria:id,nombre,parent_id', 'detalles.producto.categoria.parent:id,nombre', 'detalles.opciones.modificadorOpcion:id,nombre']);
        return [...$this->resumen($orden), 'subtotal' => $orden->subtotal, 'total' => $orden->total,
            'saldo_pendiente' => $orden->saldo_pendiente,
            'detalles' => $orden->detalles->map(fn ($detalle) => [
                'id' => $detalle->id, 'cantidad' => $detalle->cantidad, 'producto' => $detalle->producto?->nombre,
                'categoria' => $detalle->producto?->categoria?->parent?->nombre ?? $detalle->producto?->categoria?->nombre ?? 'Sin categoría',
                'precio_unitario' => (float) $detalle->precio_unitario,
                'nota' => $detalle->nota, 'opciones' => $detalle->opciones->pluck('modificadorOpcion.nombre')->filter()->values(),
            ])->values()];
    }

    private function snapshot(OrdenDetalle $detalle): array
    {
        return ['detalle_id' => $detalle->id, 'producto_id' => $detalle->producto_id,
            'producto_nombre' => $detalle->producto?->nombre, 'estacion_id' => $detalle->estacion_id,
            'estacion_nombre' => $detalle->estacion?->nombre, 'cantidad' => 1,
            'precio_unitario' => (float) $detalle->precio_unitario, 'nota' => $detalle->nota,
            'modificadores' => $detalle->opciones->map(fn ($opcion) => [
                'opcion_id' => $opcion->modificador_opcion_id,
                'nombre' => $opcion->modificadorOpcion?->nombre,
                'modificador_id' => $opcion->modificadorOpcion?->modificador_id,
                'precio_extra' => (float) $opcion->precio_extra,
            ])->values()->all()];
    }

    private function puedeAgregar(Orden $orden): bool
    {
        if ($orden->esPreordenProgramada() || $orden->estado === 'cancelado') return false;
        return $orden->tipo_flujo !== 'preorden' || $orden->estado_preorden === 'activada';
    }
}
