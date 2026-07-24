<?php

namespace App\Http\Controllers;

use App\Events\OrdenCocinaActualizadaEvent;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CocinaController extends Controller
{
    /**
     * Pedidos activos para el KDS. Cada detalle incluye su producto y categoría.
     */
    public function pedidos(Request $request)
    {
        $fecha = $request->input('fecha', now()->toDateString());

        $ordenes = Orden::query()
            ->with(['cliente:id,nombre', 'mesa:id,numero', 'detalles.producto.categoria'])
            ->whereDate('created_at', $fecha)
            ->whereIn('estado', ['pendiente', 'preparando', 'listo'])
            ->whereHas('detalles', fn ($query) => $query->where('estado_cocina', 'pendiente'))
            ->orderBy('created_at')
            ->get();

        return response()->json(['ordenes' => $ordenes]);
    }

    /**
     * Marca o desmarca un producto de una orden como servido.
     */
    public function actualizarDetalle(Request $request, OrdenDetalle $detalle)
    {
        $data = $request->validate([
            'estado_cocina' => 'required|in:pendiente,servido',
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
            ]);

            return [
                'orden' => $ordenActualizada,
                'message' => $data['estado_cocina'] === 'servido' ? 'Producto marcado como servido.' : 'Producto devuelto a pendientes.',
                'detalle' => $detalle->fresh(['producto.categoria']),
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
