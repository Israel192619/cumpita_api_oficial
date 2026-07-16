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
    public function index(Request $request)
    {
        $query = PagoOrden::with('orden');

        if ($request->has('id_orden')) {
            $query->where('id_orden', $request->input('id_orden'));
        }

        $pagos = $query->orderBy('fecha_pago', 'desc')->get();

        return response()->json($pagos, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_orden'       => 'required|exists:ordenes,id',
            'monto_recibido' => 'required|numeric|min:0.01',
            'metodo_pago'    => 'required|in:efectivo,qr',
            'tipo_pago'      => 'required|in:pago,devolucion',
            'monto_pagado'   => 'nullable|numeric',
            'cambio_devuelto' => 'nullable|numeric',
        ]);

        return DB::transaction(function () use ($request) {

            $orden = Orden::lockForUpdate()->findOrFail($request->id_orden);

            $pagosAnteriores = PagoOrden::where('id_orden', $orden->id)
                ->sum('monto_pagado');

            $saldoActual = (float) $orden->total - (float) $pagosAnteriores;
            $montoRecibido = (float) $request->monto_recibido;
            $tipoPago = $request->tipo_pago;

            if ($tipoPago === 'devolucion') {
                $montoDevolucionMaxima = max(0, (float) $pagosAnteriores - (float) $orden->total);

                if ($montoDevolucionMaxima <= 0) {
                    return response()->json([
                        'error' => 'No hay saldo para devolver en esta orden.'
                    ], 422);
                }

                // Si el frontend proporciona monto_pagado y cambio_devuelto, usarlos
                // De lo contrario, calcularlos
                if ($request->has('monto_pagado') && $request->has('cambio_devuelto')) {
                    $montoPagado = -(float) $request->monto_pagado;
                    $cambioDevuelto = -(float) $request->cambio_devuelto;
                    $montoRecibidoNegativo = -$montoRecibido;
                } else {
                    // Interpretamos $montoRecibido como la cantidad en efectivo que el cajero entrega al cliente
                    // Ej: debe devolverse 45 pero el cajero entrega 50 por falta de cambio.
                    // Guardamos:
                    // - 'monto_recibido' => negativo del efectivo entregado (ej -50)
                    // - 'monto_pagado' => negativo del monto aplicado a la devolución (ej -45)
                    // - 'cambio_devuelto' => negativo del excedente entregado al cliente (ej -5)

                    $montoEntregado = $montoRecibido; // positivo tal como viene del frontend
                    $montoAplicado = min($montoEntregado, $montoDevolucionMaxima);
                    $excedente = max(0, $montoEntregado - $montoAplicado);

                    $montoPagado = -$montoAplicado; // lo que se resta de lo abonado al pedido
                    $cambioDevuelto = $excedente > 0 ? -$excedente : 0;
                    $montoRecibidoNegativo = -$montoEntregado;
                }

                $montoRecibido = $montoRecibidoNegativo;
                $montoAbonado = $montoPagado;
            } else {
                if ($saldoActual <= 0) {
                    return response()->json([
                        'error' => 'La orden ya se encuentra completamente pagada.'
                    ], 422);
                }

                $montoAbonado = min($montoRecibido, $saldoActual);
                $cambioDevuelto = 0;

                if ($montoRecibido > $saldoActual) {
                    $cambioDevuelto = $montoRecibido - $saldoActual;
                }
            }

            $pago = PagoOrden::create([
                'id_orden'        => $orden->id,
                'monto_recibido'  => $montoRecibido,
                'monto_pagado'    => $montoAbonado,
                'cambio_devuelto' => $cambioDevuelto,
                'metodo_pago'     => $request->metodo_pago,
                'tipo_pago'       => $tipoPago,
                'fecha_pago'      => now(),
            ]);

            $pagosTotales = PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado');
            $orden->estado_pago = $pagosTotales <= 0
                ? 'pendiente'
                : ($pagosTotales < (float) $orden->total ? 'parcial' : 'completado');
            $orden->save();

            $saldoPendiente = max(0, (float) $orden->total - (float) $pagosTotales);

            return response()->json([
                'mensaje'         => 'Pago procesado correctamente.',
                'pago'            => $pago,
                'monto_recibido'  => $montoRecibido,
                'monto_abonado'   => $montoAbonado,
                'cambio_devuelto' => round($cambioDevuelto, 2),
                'saldo_pendiente' => round($saldoPendiente, 2),
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
        return response()->json([
            'error' => 'No está permitido modificar un pago registrado.'
        ], 403);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        return response()->json([
            'error' => 'No está permitido eliminar registros de pago.'
        ], 403);
    }
}