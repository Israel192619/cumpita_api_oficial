<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnularMovimientoCajaRequest;
use App\Http\Requests\StoreMovimientoCajaRequest;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use Illuminate\Support\Facades\DB;

class MovimientoCajaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $movimientos = MovimientoCaja::with([
            'usuario:id,name,username',
            'anulador:id,name,username',
        ])->whereHas('caja', fn ($query) => $query->where('user_id', auth('api')->id()))
            ->latest()
            ->get();

        return response()->json(['movimientos' => $movimientos]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMovimientoCajaRequest $request)
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

            $movimiento = MovimientoCaja::create([
                ...$request->validated(),
                'caja_id' => $caja->id,
                'usuario_id' => auth('api')->id(),
                'estado' => 'ACTIVO',
            ])->load('usuario:id,name,username');

            return response()->json([
                'message' => 'Movimiento registrado correctamente.',
                'movimiento' => $movimiento,
            ], 201);
        });
    }

    public function anular(AnularMovimientoCajaRequest $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $movimiento = MovimientoCaja::whereKey($id)->lockForUpdate()->firstOrFail();
            $caja = Caja::whereKey($movimiento->caja_id)
                ->where('user_id', auth('api')->id())
                ->where('estado', 'abierta')
                ->lockForUpdate()
                ->first();

            if (!$caja) {
                return response()->json([
                    'message' => 'El movimiento no pertenece a una caja abierta válida del usuario.',
                ], 403);
            }

            if ($movimiento->estado === 'ANULADO') {
                return response()->json(['message' => 'El movimiento ya fue anulado.'], 422);
            }

            $movimiento->update([
                'estado' => 'ANULADO',
                'anulado_por' => auth('api')->id(),
                'anulado_en' => now(),
                'motivo_anulacion' => $request->validated('motivo_anulacion'),
            ]);

            return response()->json([
                'message' => 'Movimiento anulado correctamente.',
                'movimiento' => $movimiento->fresh()->load(['usuario:id,name,username', 'anulador:id,name,username']),
            ]);
        });
    }
}
