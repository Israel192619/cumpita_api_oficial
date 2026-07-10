<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\PagoOrden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoOrdenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $pagos = PagoOrden::with('orden')->orderBy('fecha_pago', 'desc')->get();
        return response()->json($pagos, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
        'id_orden'     => 'required|exists:ordenes,id_orden',
        'monto_recibido' => 'required|numeric|min:0.01',
        'metodo_pago'  => 'required|string', // ej: 'tarjeta_pos', 'efectivo'
        'tipo_pago'    => 'required|in:reserva,saldo,total',
        ]);

        return DB::transaction(function () use ($request) {
            $orden = Orden::lockForUpdate()->findOrFail($request->id_orden);

            $pagosAnteriores = PagoOrden::where('id_orden', $orden->id_orden)->sum('monto_pagado');
            $saldoActual = (float)$orden->total_orden - (float)$pagosAnteriores;
            
            $montoRecibido = (float)$request->monto_recibido;

            // 1. Determinar cuánto se va a abonar realmente a la deuda
            // Si paga con más de lo que debe, el abono real es solo el saldo pendiente
            $montoAbonado = ($montoRecibido > $saldoActual) ? $saldoActual : $montoRecibido;

            // 2. Calcular el cambio/vuelto devuelto
            $cambioDevuelto = 0.00;
            if ($montoRecibido > $saldoActual) {
                // Solo permitimos vuelto si el método es efectivo
                if ($request->metodo_pago === 'efectivo') {
                    $cambioDevuelto = $montoRecibido - $saldoActual;
                } else {
                    return response()->json([
                        'error' => 'No se puede devolver cambio en pagos que no sean en efectivo.'
                    ], 422);
                }
            }

            // 3. Registrar el pago con todo el desglose para la auditoría de caja
            $pago = PagoOrden::create([
                'id_orden'        => $request->id_orden,
                'monto_recibido'  => $montoRecibido,
                'monto_pagado'    => $montoAbonado, // Esto es lo que resta a la deuda
                'cambio_devuelto' => $cambioDevuelto,
                'metodo_pago'     => $request->metodo_pago,
                'tipo_pago'       => $request->tipo_pago,
                'fecha_pago'      => now(),
            ]);

            // 4. Actualizar estado de la orden
            $saldoFinal = $saldoActual - $montoAbonado;
            if ($saldoFinal <= 0) {
                $orden->estado_orden = 'pagado';
            } else {
                $orden->estado_orden = 'reservado';
            }
            $orden->save();

            return response()->json([
                'mensaje'         => 'Pago procesado correctamente.',
                'monto_recibido'  => $montoRecibido,
                'monto_abonado'   => $montoAbonado,
                'cambio_devuelto' => round($cambioDevuelto, 2),
                'saldo_pendiente' => round($saldoFinal, 2),
                'estado_orden'    => $orden->estado_orden
            ], 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pago = PagoOrden::with('orden')->findOrFail($id);
        return response()->json($pago, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        return response()->json(['error' => 'No está permitido modificar un pago registrado.'], 403);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        return response()->json(['error' => 'No está permitido eliminar registros de pago.'], 403);
    }
}
