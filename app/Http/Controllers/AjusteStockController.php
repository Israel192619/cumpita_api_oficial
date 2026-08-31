<?php

namespace App\Http\Controllers;

use App\Events\StockActualizadoEvent;
use App\Models\AjusteStock;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AjusteStockController extends Controller
{
    public function index()
    {
        return response()->json([
            'ajustes' => AjusteStock::with([
                'producto:id,nombre',
                'usuario:id,name,username',
            ])->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tipo' => ['required', Rule::in(['ENTRADA', 'SALIDA', 'CORRECCION'])],
            'cantidad' => 'required|integer|min:0',
            'motivo' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data) {
            if ($data['tipo'] !== 'CORRECCION' && (int) $data['cantidad'] < 1) {
                return response()->json(['message' => 'La cantidad debe ser mayor que cero.'], 422);
            }

            $producto = Producto::whereKey($data['producto_id'])->lockForUpdate()->firstOrFail();

            if (!$producto->maneja_stock || $producto->stock === null) {
                return response()->json(['message' => 'El producto no tiene gestión de stock activa.'], 422);
            }

            $anterior = (int) $producto->stock;
            $final = match ($data['tipo']) {
                'ENTRADA' => $anterior + (int) $data['cantidad'],
                'SALIDA' => $anterior - (int) $data['cantidad'],
                'CORRECCION' => (int) $data['cantidad'],
            };

            if ($final < 0) {
                return response()->json(['message' => 'La salida no puede dejar el stock por debajo de cero.'], 422);
            }

            $producto->update(['stock' => $final]);
            $ajuste = AjusteStock::create([
                'producto_id' => $producto->id,
                'tipo' => $data['tipo'],
                'cantidad' => $data['cantidad'],
                'stock_anterior' => $anterior,
                'stock_final' => $final,
                'motivo' => trim($data['motivo'] ?? '') ?: 'Sin motivo especificado',
                'usuario_id' => auth('api')->id(),
            ])->load(['producto:id,nombre', 'usuario:id,name,username']);

            StockActualizadoEvent::dispatch($producto->id, $final);

            return response()->json([
                'message' => 'Ajuste de stock registrado correctamente.',
                'ajuste' => $ajuste,
                'producto' => $producto->fresh(),
            ], 201);
        });
    }

    public function revertir(AjusteStock $ajuste)
    {
        return DB::transaction(function () use ($ajuste) {
            $ajuste = AjusteStock::whereKey($ajuste->id)->lockForUpdate()->firstOrFail();
            if ($ajuste->revertido_por_ajuste_id) {
                return response()->json(['message' => 'Este ajuste ya fue revertido.'], 422);
            }

            $diferencia = (int) $ajuste->stock_final - (int) $ajuste->stock_anterior;
            if ($diferencia === 0) {
                return response()->json(['message' => 'Este ajuste no modificó el stock y no requiere reversión.'], 422);
            }

            $producto = Producto::whereKey($ajuste->producto_id)->lockForUpdate()->firstOrFail();
            $anterior = (int) $producto->stock;
            $final = $anterior - $diferencia;
            if ($final < 0) {
                return response()->json(['message' => 'No se puede revertir porque el stock actual no es suficiente.'], 422);
            }

            $tipo = $diferencia > 0 ? 'SALIDA' : 'ENTRADA';
            $reversion = AjusteStock::create([
                'producto_id' => $producto->id,
                'tipo' => $tipo,
                'cantidad' => abs($diferencia),
                'stock_anterior' => $anterior,
                'stock_final' => $final,
                'motivo' => 'Reversión del ajuste #' . $ajuste->id,
                'usuario_id' => auth('api')->id(),
            ]);

            $producto->update(['stock' => $final]);
            $ajuste->update(['revertido_por_ajuste_id' => $reversion->id]);
            StockActualizadoEvent::dispatch($producto->id, $final);

            return response()->json([
                'message' => 'Ajuste revertido correctamente.',
                'ajuste' => $ajuste->fresh()->load(['producto:id,nombre', 'usuario:id,name,username', 'reversion']),
                'reversion' => $reversion->load(['producto:id,nombre', 'usuario:id,name,username']),
            ]);
        });
    }
}
