<?php

namespace App\Http\Controllers;

use App\Events\StockActualizadoEvent;
use App\Models\AjusteStock;
use App\Models\Producto;
use App\Models\ModificadorOpcion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AjusteStockController extends Controller
{
    public function index()
    {
        $ajustes = AjusteStock::with([
                'producto:id,nombre',
                'modificadorOpcion:id,nombre',
                'usuario:id,name,username',
            ])->latest()->get();
        $ajustes->each(fn ($ajuste) => $ajuste->elemento_nombre = $ajuste->producto?->nombre ?? $ajuste->modificadorOpcion?->nombre);
        return response()->json(['ajustes' => $ajustes]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'nullable|required_without:modificador_opcion_id|exists:productos,id',
            'modificador_opcion_id' => 'nullable|required_without:producto_id|exists:modificador_opciones,id',
            'tipo' => ['required', Rule::in(['ENTRADA', 'SALIDA', 'CORRECCION'])],
            'cantidad' => 'required|integer|min:0',
            'motivo' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data) {
            if ($data['tipo'] !== 'CORRECCION' && (int) $data['cantidad'] < 1) {
                return response()->json(['message' => 'La cantidad debe ser mayor que cero.'], 422);
            }

            if (!empty($data['producto_id']) && !empty($data['modificador_opcion_id'])) {
                return response()->json(['message' => 'Selecciona un producto o una opción, no ambos.'], 422);
            }
            $inventariable = !empty($data['producto_id'])
                ? Producto::whereKey($data['producto_id'])->lockForUpdate()->firstOrFail()
                : ModificadorOpcion::whereKey($data['modificador_opcion_id'])->lockForUpdate()->firstOrFail();

            if (!$inventariable->maneja_stock || $inventariable->stock === null) return response()->json(['message' => 'El elemento no tiene gestión de stock activa.'], 422);
            $anterior = (int) $inventariable->stock;
            $final = match ($data['tipo']) {
                'ENTRADA' => $anterior + (int) $data['cantidad'],
                'SALIDA' => $anterior - (int) $data['cantidad'],
                'CORRECCION' => (int) $data['cantidad'],
            };

            if ($final < 0) {
                return response()->json(['message' => 'La salida no puede dejar el stock por debajo de cero.'], 422);
            }

            $inventariable->update(['stock' => $final]);
            $ajuste = AjusteStock::create([
                'producto_id' => $data['producto_id'] ?? null,
                'modificador_opcion_id' => $data['modificador_opcion_id'] ?? null,
                'tipo' => $data['tipo'],
                'cantidad' => $data['cantidad'],
                'stock_anterior' => $anterior,
                'stock_final' => $final,
                'motivo' => trim($data['motivo'] ?? '') ?: 'Sin motivo especificado',
                'usuario_id' => auth('api')->id(),
            ])->load(['producto:id,nombre', 'modificadorOpcion:id,nombre', 'usuario:id,name,username']);

            if (!empty($data['producto_id'])) StockActualizadoEvent::dispatch($inventariable->id, $final);

            return response()->json([
                'message' => 'Ajuste de stock registrado correctamente.',
                'ajuste' => $ajuste,
                'inventariable' => $inventariable->fresh(),
            ], 201);
        });
    }

    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'items' => 'required|array|min:1|max:100',
            'items.*.modificador_opcion_id' => 'required|integer|distinct|exists:modificador_opciones,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'motivo' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data) {
            $optionIds = collect($data['items'])->pluck('modificador_opcion_id')->map(fn ($id) => (int) $id);
            $assignedOptionIds = Producto::findOrFail($data['producto_id'])->opciones()
                ->whereIn('modificador_opciones.id', $optionIds)
                ->pluck('modificador_opciones.id');

            if ($assignedOptionIds->count() !== $optionIds->count()) {
                return response()->json(['message' => 'Una de las opciones no pertenece a este producto.'], 422);
            }

            $options = ModificadorOpcion::whereIn('id', $optionIds)->lockForUpdate()->get()->keyBy('id');
            $ajustes = collect();
            $motivo = trim($data['motivo'] ?? '') ?: 'Reabastecimiento de opciones desde POS';

            foreach ($options as $option) {
                if (!$option->maneja_stock || $option->stock === null) {
                    return response()->json(['message' => "La opción {$option->nombre} no tiene gestión de stock activa."], 422);
                }
            }

            foreach ($data['items'] as $item) {
                $option = $options->get((int) $item['modificador_opcion_id']);
                $previousStock = (int) $option->stock;
                $finalStock = $previousStock + (int) $item['cantidad'];
                $option->update(['stock' => $finalStock]);
                $ajustes->push(AjusteStock::create([
                    'producto_id' => null,
                    'modificador_opcion_id' => $option->id,
                    'tipo' => 'ENTRADA',
                    'cantidad' => $item['cantidad'],
                    'stock_anterior' => $previousStock,
                    'stock_final' => $finalStock,
                    'motivo' => $motivo,
                    'usuario_id' => auth('api')->id(),
                ]));

            }

            return response()->json([
                'message' => 'Reabastecimiento registrado correctamente.',
                'ajustes' => AjusteStock::with(['modificadorOpcion:id,nombre', 'usuario:id,name,username'])
                    ->whereKey($ajustes->pluck('id'))
                    ->get(),
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

            $inventariable = $ajuste->producto_id
                ? Producto::whereKey($ajuste->producto_id)->lockForUpdate()->firstOrFail()
                : ModificadorOpcion::whereKey($ajuste->modificador_opcion_id)->lockForUpdate()->firstOrFail();
            $anterior = (int) $inventariable->stock;
            $final = $anterior - $diferencia;
            if ($final < 0) {
                return response()->json(['message' => 'No se puede revertir porque el stock actual no es suficiente.'], 422);
            }

            $tipo = $diferencia > 0 ? 'SALIDA' : 'ENTRADA';
            $reversion = AjusteStock::create([
                'producto_id' => $ajuste->producto_id,
                'modificador_opcion_id' => $ajuste->modificador_opcion_id,
                'tipo' => $tipo,
                'cantidad' => abs($diferencia),
                'stock_anterior' => $anterior,
                'stock_final' => $final,
                'motivo' => 'Reversión del ajuste #' . $ajuste->id,
                'usuario_id' => auth('api')->id(),
            ]);

            $inventariable->update(['stock' => $final]);
            $ajuste->update(['revertido_por_ajuste_id' => $reversion->id]);
            if ($ajuste->producto_id) StockActualizadoEvent::dispatch($inventariable->id, $final);

            return response()->json([
                'message' => 'Ajuste revertido correctamente.',
                'ajuste' => $ajuste->fresh()->load(['producto:id,nombre', 'modificadorOpcion:id,nombre', 'usuario:id,name,username', 'reversion']),
                'reversion' => $reversion->load(['producto:id,nombre', 'modificadorOpcion:id,nombre', 'usuario:id,name,username']),
            ]);
        });
    }
}
