<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CocinaController extends Controller
{
    /**
     * Pedidos activos para el KDS. Cada detalle incluye su producto y categoría.
     */
    public function pedidos(Request $request)
    {
        $fecha = $request->input('fecha', now()->toDateString());
        $usuario = auth('api')->user();
        $estacionId = $usuario?->estacion_id;

        // Construir query base (sin filtro por estación) para contar órdenes antes del filtrado
        $baseQuery = Orden::query()
            ->with(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria', 'detalles.estacion'])
            ->whereDate('created_at', $fecha)
            ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->whereHas('detalles', fn ($q) => $q->where('estado_cocina', 'pendiente'))
            ->orderBy('created_at');

        $ordenesAntes = (clone $baseQuery)->get();
        $totalAntes = $ordenesAntes->count();

        if ($estacionId !== null) {
            $ordenes = (clone $baseQuery)
                ->whereHas('detalles', fn ($q) => $q->where('estado_cocina', 'pendiente')->where('estacion_id', $estacionId))
                ->get();
        } else {
            $ordenes = $ordenesAntes;
        }

        $totalDespues = $ordenes->count();

        // Log temporal solo si se solicita debug
        if ($request->boolean('debug')) {
            $muestra = $ordenes->map(function (Orden $o) {
                return [
                    'id' => $o->id,
                    'detalles' => $o->detalles->map(function ($d) {
                        return [
                            'id' => $d->id,
                            'estacion_id' => $d->estacion_id,
                            'producto_id' => $d->producto?->id ?? null,
                            'producto_estacion_id' => $d->producto?->estacion_id ?? null,
                        ];
                    })->values(),
                ];
            })->take(10)->values();

            $debug = [
                'usuario' => [
                    'id' => $usuario?->id ?? null,
                    'estacion_id' => $usuario?->estacion_id ?? null,
                    'estacion' => $usuario?->estacion?->toArray() ?? null,
                ],
                'total_antes' => $totalAntes,
                'total_despues' => $totalDespues,
                'ordenes_muestra' => $muestra,
            ];

            Log::info('cocina.pedidos.debug', $debug);

            return response()->json(['ordenes' => $ordenes, 'debug' => $debug]);
        }

        // Si no hay debug, igualmente registramos un log informativo breve cuando hay estación asignada
        if ($estacionId !== null) {
            Log::info('cocina.pedidos.summary', [
                'usuario_id' => $usuario?->id ?? null,
                'estacion_id' => $estacionId,
                'total_antes' => $totalAntes,
                'total_despues' => $totalDespues,
            ]);
        }

        return response()->json(['ordenes' => $ordenes]);
    }

    /**
     * Marca o desmarca un producto de una orden como servido.
     */
    public function actualizarDetalle(Request $request, OrdenDetalle $detalle)
    {
        $data = $request->validate([
            // Accept the expanded set of detail states for the gradual rollout.
            'estado_cocina' => 'required|in:pendiente,en_preparacion,listo_para_recoger,recogido,servido',
        ]);

        $resultado = DB::transaction(function () use ($detalle, $data) {
            $detalle = OrdenDetalle::lockForUpdate()->findOrFail($detalle->id);
            $detalle->update([
                'estado_cocina' => $data['estado_cocina'],
                'fecha_servido' => $data['estado_cocina'] === 'servido' ? now() : null,
            ]);

            $orden = Orden::lockForUpdate()->findOrFail($detalle->orden_id);
            $pendientes = $orden->detalles()->where('estado_cocina', 'pendiente')->exists();
            $orden->estado = $pendientes ? 'preparando' : 'listo';
            $orden->save();

            $ordenActualizada = $orden->fresh([
                'cliente:id,nombre',
                'mesa:id,numero',
                'detalles.producto.categoria',
                'detalles.estacion',
            ]);

            return [
                'orden' => $ordenActualizada,
                'message' => $data['estado_cocina'] === 'servido' ? 'Producto marcado como servido.' : 'Producto devuelto a pendientes.',
                'detalle' => $detalle->fresh(['producto.categoria', 'estacion']),
                'orden_estado' => $orden->estado,
                'orden_id' => $orden->id,
            ];
        });

        // Reverb se notifica solo cuando la transacción ya fue confirmada.
        event(new OrdenCocinaActualizadaEvent($resultado['orden']));

        unset($resultado['orden']);
        return response()->json($resultado);
    }
}
