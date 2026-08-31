<?php

namespace App\Http\Controllers;

use App\Events\ReservaStockActualizadaEvent;
use App\Models\Producto;
use App\Models\ReservaStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservaStockController extends Controller
{
    private const MINUTOS_VIGENCIA = 5;

    public function sincronizar(Request $request)
    {
        $data = $request->validate([
            'sesion_id' => 'required|uuid',
            'items' => 'present|array',
            'items.*.producto_id' => 'required|integer|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($data) {
            ReservaStock::where('expira_en', '<=', now())->delete();
            $cantidades = collect($data['items'])->groupBy('producto_id')->map(fn ($items) => (int) $items->sum('cantidad'));
            $productoIds = $cantidades->keys()->map(fn ($id) => (int) $id)->values();
            $anteriores = ReservaStock::where('sesion_id', $data['sesion_id'])->pluck('producto_id')->map(fn ($id) => (int) $id);
            $afectados = $productoIds->merge($anteriores)->unique()->sort()->values();
            $productos = Producto::whereIn('id', $afectados)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($productoIds as $productoId) {
                $producto = $productos->get($productoId);
                $cantidad = $cantidades->get($productoId);
                if (!$producto?->maneja_stock || $producto->stock === null) continue;
                $reservadoPorOtros = (int) ReservaStock::activas()->where('producto_id', $productoId)
                    ->where('sesion_id', '!=', $data['sesion_id'])->sum('cantidad');
                $disponible = max(0, (int) $producto->stock - $reservadoPorOtros);
                if ($cantidad > $disponible) {
                    $propia = (int) ReservaStock::activas()->where('producto_id', $productoId)->where('sesion_id', $data['sesion_id'])->value('cantidad');
                    return response()->json(['message' => 'Ya no hay suficiente stock para ' . $producto->nombre . '.', 'producto_id' => $productoId, 'cantidad_reservada' => $propia], 422);
                }
            }

            ReservaStock::where('sesion_id', $data['sesion_id'])->whereNotIn('producto_id', $productoIds)->delete();
            foreach ($productoIds as $productoId) {
                $producto = $productos->get($productoId);
                if (!$producto?->maneja_stock || $producto->stock === null) continue;
                ReservaStock::updateOrCreate(['producto_id' => $productoId, 'sesion_id' => $data['sesion_id']], [
                    'usuario_id' => auth('api')->id(), 'cantidad' => $cantidades->get($productoId), 'expira_en' => now()->addMinutes(self::MINUTOS_VIGENCIA),
                ]);
            }
            ReservaStockActualizadaEvent::dispatch($afectados->all());
            return response()->json(['message' => 'Reserva actualizada.', 'expira_en' => now()->addMinutes(self::MINUTOS_VIGENCIA)]);
        });
    }

    public function liberar(Request $request)
    {
        $data = $request->validate(['sesion_id' => 'required|uuid']);
        $ids = ReservaStock::where('sesion_id', $data['sesion_id'])->pluck('producto_id')->all();
        ReservaStock::where('sesion_id', $data['sesion_id'])->delete();
        if ($ids) ReservaStockActualizadaEvent::dispatch($ids);
        return response()->noContent();
    }
}
