<?php

namespace App\Http\Controllers;

use App\Events\CajaActualizadaEvent;
use App\Http\Requests\AnularGastoCajaRequest;
use App\Http\Requests\StoreGastoCajaRequest;
use App\Models\Caja;
use App\Models\GastoCaja;
use Illuminate\Support\Facades\DB;

class GastoCajaController extends Controller
{
    public function index()
    {
        $gastos = GastoCaja::with([
            'usuario:id,name,username',
            'anulador:id,name,username',
        ])->whereHas('caja', fn ($query) => $query->where('user_id', auth('api')->id()))
            ->latest()
            ->get();

        return response()->json(['gastos' => $gastos]);
    }

    public function store(StoreGastoCajaRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $caja = Caja::where('user_id', auth('api')->id())
                ->where('estado', 'abierta')
                ->latest('fecha_apertura')
                ->lockForUpdate()
                ->first();

            if (!$caja) {
                return response()->json(['message' => 'No tienes una caja abierta.'], 422);
            }

            $gasto = GastoCaja::create([
                ...$request->validated(),
                'caja_id' => $caja->id,
                'usuario_id' => auth('api')->id(),
                'estado' => 'ACTIVO',
            ])->load('usuario:id,name,username');
            event(new CajaActualizadaEvent($caja->id, 'gasto_registrado'));

            return response()->json([
                'message' => 'Gasto registrado correctamente.',
                'gasto' => $gasto,
            ], 201);
        });
    }

    public function anular(AnularGastoCajaRequest $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $gasto = GastoCaja::whereKey($id)->lockForUpdate()->firstOrFail();
            $caja = Caja::whereKey($gasto->caja_id)
                ->where('user_id', auth('api')->id())
                ->where('estado', 'abierta')
                ->lockForUpdate()
                ->first();

            if (!$caja) {
                return response()->json([
                    'message' => 'El gasto no pertenece a una caja abierta válida del usuario.',
                ], 403);
            }

            if ($gasto->estado === 'ANULADO') {
                return response()->json(['message' => 'El gasto ya fue anulado.'], 422);
            }

            $gasto->update([
                'estado' => 'ANULADO',
                'anulado_por' => auth('api')->id(),
                'anulado_en' => now(),
                'motivo_anulacion' => $request->validated('motivo_anulacion'),
            ]);

            return response()->json([
                'message' => 'Gasto anulado correctamente.',
                'gasto' => $gasto->fresh()->load(['usuario:id,name,username', 'anulador:id,name,username']),
            ]);
        });
    }
}
