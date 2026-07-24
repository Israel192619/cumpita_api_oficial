<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaController extends Controller
{
    /**
     * Historial de cajas del usuario autenticado.
     */
    public function index()
    {
        $cajas = Caja::with('user:id,name,username')
            ->where('user_id', auth('api')->id())
            ->latest('fecha_apertura')
            ->get();

        return response()->json(['cajas' => $cajas]);
    }

    /**
     * Devuelve la caja abierta del usuario, si existe.
     */
    public function actual()
    {
        $caja = Caja::with('user:id,name,username')
            ->where('user_id', auth('api')->id())
            ->where('estado', 'abierta')
            ->latest('fecha_apertura')
            ->first();

        if (!$caja) {
            return response()->json(['caja' => null]);
        }

        return response()->json([
            'caja' => $caja,
            'resumen' => $this->resumen($caja),
        ]);
    }

    /**
     * Abre una nueva jornada de caja para el cajero autenticado.
     */
    public function abrir(Request $request)
    {
        $data = $request->validate([
            'monto_apertura' => 'required|numeric|min:0',
            'observacion_apertura' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($data) {
            $usuarioId = auth('api')->id();

            $cajaAbierta = Caja::where('user_id', $usuarioId)
                ->where('estado', 'abierta')
                ->lockForUpdate()
                ->first();

            if ($cajaAbierta) {
                return response()->json([
                    'message' => 'Ya tienes una caja abierta. Debes cerrarla antes de abrir otra.',
                    'caja' => $cajaAbierta,
                ], 422);
            }

            $caja = Caja::create([
                'user_id' => $usuarioId,
                'monto_apertura' => $data['monto_apertura'],
                'fecha_apertura' => now(),
                'estado' => 'abierta',
                'observacion_apertura' => $data['observacion_apertura'] ?? null,
            ]);

            return response()->json([
                'message' => 'Caja abierta correctamente.',
                'caja' => $caja,
            ], 201);
        });
    }

    /**
     * Muestra una caja propia junto con sus cobros en efectivo.
     */
    public function show(string $id)
    {
        $caja = $this->cajaDelUsuario($id);

        return response()->json([
            'caja' => $caja->load('user:id,name,username'),
            'resumen' => $this->resumen($caja),
        ]);
    }

    /**
     * Cierra la caja. El efectivo esperado se calcula desde los pagos ya registrados.
     */
    public function cerrar(Request $request, string $id)
    {
        $data = $request->validate([
            'monto_cierre' => 'required|numeric|min:0',
            'observacion_cierre' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $caja = Caja::where('id', $id)
                ->where('user_id', auth('api')->id())
                ->lockForUpdate()
                ->firstOrFail();

            if ($caja->estado !== 'abierta') {
                return response()->json([
                    'message' => 'Esta caja ya fue cerrada.',
                ], 422);
            }

            $resumen = $this->resumen($caja);
            $montoCierre = round((float) $data['monto_cierre'], 2);
            $esperado = $resumen['monto_esperado'];

            $caja->update([
                'monto_esperado' => $esperado,
                'monto_cierre' => $montoCierre,
                'diferencia' => round($montoCierre - $esperado, 2),
                'fecha_cierre' => now(),
                'estado' => 'cerrada',
                'observacion_cierre' => $data['observacion_cierre'] ?? null,
            ]);

            return response()->json([
                'message' => 'Caja cerrada correctamente.',
                'caja' => $caja->fresh(),
                'resumen' => $resumen,
            ]);
        });
    }

    /**
     * No se permite modificar una caja ya abierta o cerrada; mantiene la auditoría.
     */
    public function update(Request $request, string $id)
    {
        return response()->json(['message' => 'No está permitido modificar una caja registrada.'], 403);
    }

    public function destroy(string $id)
    {
        return response()->json(['message' => 'No está permitido eliminar una caja registrada.'], 403);
    }

    private function cajaDelUsuario(string $id): Caja
    {
        return Caja::where('id', $id)
            ->where('user_id', auth('api')->id())
            ->firstOrFail();
    }

    /**
     * El monto de cada pago en efectivo es el monto aplicado a la orden.
     * Las devoluciones se registran con importe negativo y reducen el efectivo esperado.
     */
    private function resumen(Caja $caja): array
    {
        $totalEfectivo = round((float) $caja->pagos()->sum('monto_pagado'), 2);
        $montoApertura = round((float) $caja->monto_apertura, 2);

        return [
            'monto_apertura' => $montoApertura,
            'ingresos_efectivo' => $totalEfectivo,
            'monto_esperado' => round($montoApertura + $totalEfectivo, 2),
            'cantidad_pagos_efectivo' => $caja->pagos()->count(),
        ];
    }
}
