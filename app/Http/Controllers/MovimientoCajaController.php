<?php

namespace App\Http\Controllers;

use App\Events\CajaActualizadaEvent;
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
        $resultado = DB::transaction(function () use ($request) {
            $usuarioId = (int) auth('api')->id();
            $caja = Caja::where(function ($query) use ($usuarioId) {
                    $query->where('user_id', $usuarioId)
                        ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
                })
                ->where('estado', 'abierta')
                ->latest('fecha_apertura')
                ->lockForUpdate()
                ->first();

            if (!$caja) {
                abort(422, 'No tienes acceso a una caja abierta.');
            }

            if ($request->validated('tipo') === 'RETIRO') {
                $disponible = round(
                    (float) $caja->monto_apertura
                    + (float) $caja->pagos()->where('metodo_pago', 'efectivo')->sum('monto_pagado')
                    + (float) $caja->movimientos()->where('estado', 'ACTIVO')->where('tipo', 'INGRESO')->sum('monto')
                    - (float) $caja->movimientos()->where('estado', 'ACTIVO')->where('tipo', 'RETIRO')->sum('monto')
                    - (float) $caja->gastos()->where('estado', 'ACTIVO')->sum('monto'),
                    2
                );
                abort_if((float) $request->validated('monto') > $disponible, 422,
                    'El retiro supera el efectivo disponible de la caja (Bs '.number_format($disponible, 2, ',', '.').').');
            }

            $movimiento = MovimientoCaja::create([
                'tipo' => $request->validated('tipo'),
                'monto' => $request->validated('monto'),
                'motivo' => $request->validated('motivo'),
                'caja_id' => $caja->id,
                'usuario_id' => auth('api')->id(),
                'estado' => 'ACTIVO',
            ])->load('usuario:id,name,username');

            return [
                'message' => 'Movimiento registrado correctamente.',
                'movimiento' => $movimiento,
                'caja_id' => $caja->id,
            ];
        });

        event(new CajaActualizadaEvent($resultado['caja_id'], 'movimiento_registrado'));

        return response()->json([
            'message' => $resultado['message'],
            'movimiento' => $resultado['movimiento'],
        ], 201);
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
