<?php

namespace App\Http\Controllers;

use App\Events\CajaActualizadaEvent;
use App\Events\ServicioFichaActualizadaEvent;
use App\Models\Orden;
use App\Models\PagoOrden;
use App\Models\Caja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoOrdenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = PagoOrden::with('orden', 'caja', 'usuario:id,name,username');

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
            abort_if($orden->estado === 'cancelado', 422,
                'No se pueden registrar pagos en una orden cancelada.');
            $cajaId = null;

            // Solo el efectivo entra a la caja. Para cobrar efectivo debe existir
            // una caja física abierta a la que el cajero está autorizado.
            if ($request->metodo_pago === 'efectivo') {
                $usuarioId = auth('api')->id();
                $caja = Caja::where(function ($query) use ($usuarioId) {
                        $query->where('user_id', $usuarioId)
                            ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
                    })
                    ->where('estado', 'abierta')
                    ->lockForUpdate()
                    ->first();

                if (!$caja) {
                    return response()->json([
                        'error' => 'No tienes acceso a una caja abierta para registrar pagos en efectivo.'
                    ], 422);
                }

                $cajaId = $caja->id;
            }

            $pagosAnteriores = PagoOrden::where('id_orden', $orden->id)
                ->sum('monto_pagado');

            $saldoActual = (float) $orden->total - (float) $pagosAnteriores;
            $montoRecibido = (float) $request->monto_recibido;
            $tipoPago = $request->tipo_pago;

            $totalPagado = PagoOrden::where('id_orden', $orden->id)
            ->where('tipo_pago', '!=', 'devolucion')
            ->sum('monto_pagado');

            $totalDevuelto = abs(
                PagoOrden::where('id_orden', $orden->id)
                    ->where('tipo_pago', 'devolucion')
                    ->sum('monto_pagado')
            );

            if ($tipoPago === 'devolucion') {
                $saldoDisponibleParaDevolver = $totalPagado - $totalDevuelto - (float) $orden->total;
                $montoDevolucionMaxima = max(0, (float) $pagosAnteriores - (float) $orden->total);

                // if ($montoDevolucionMaxima <= 0) {
                //     return response()->json([
                //         'error' => 'No hay saldo para devolver en esta orden.'
                //     ], 422);
                // }
                if ($saldoDisponibleParaDevolver <= 0) {
                    return response()->json([
                        'error' => 'No existe un monto disponible para devolver.'
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
                'caja_id'          => $cajaId,
                'user_id'          => auth('api')->id(),
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
            if ($orden->tipo_orden === 'delivery') {
                $orden->delivery_monto_esperado = null;
                $orden->delivery_cambio_preparado = false;
                $orden->delivery_cambio_preparado_por = null;
                $orden->delivery_cambio_preparado_en = null;
            }
            $orden->save();

            $saldoPendiente = max(0, (float) $orden->total - (float) $pagosTotales);

            event(new CajaActualizadaEvent(
                (int) ($cajaId ?? 0),
                $tipoPago === 'devolucion' ? 'devolucion_registrada' : 'pago_registrado'
            ));
            DB::afterCommit(fn () => event(ServicioFichaActualizadaEvent::desdeOrden($orden, 'pago_delivery')));

            return response()->json([
                'mensaje'         => 'Pago procesado correctamente.',
                'pago'            => $pago->load('usuario:id,name,username'),
                'monto_recibido'  => $montoRecibido,
                'monto_abonado'   => $montoAbonado,
                'cambio_devuelto' => round($cambioDevuelto, 2),
                'saldo_pendiente' => round($saldoPendiente, 2),
            ], 201);
        });
    }

    public function storeDividido(Request $request)
    {
        $data = $request->validate([
            'id_orden' => ['required', 'exists:ordenes,id'],
            'pagos' => ['required', 'array', 'min:2', 'max:10'],
            'pagos.*.metodo_pago' => ['required', 'in:efectivo,qr'],
            'pagos.*.monto_aplicado' => ['required', 'numeric', 'min:0.01'],
            'pagos.*.monto_recibido' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        return DB::transaction(function () use ($data) {
            $orden = Orden::lockForUpdate()->findOrFail($data['id_orden']);
            abort_if($orden->estado === 'cancelado', 422, 'No se pueden registrar pagos en una orden cancelada.');

            $pagosAnteriores = round((float) PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado'), 2);
            $saldoActual = round(max(0, (float) $orden->total - $pagosAnteriores), 2);
            abort_if($saldoActual <= 0, 422, 'La orden ya se encuentra completamente pagada.');

            $partes = collect($data['pagos'])->map(function (array $pago) {
                $aplicado = round((float) $pago['monto_aplicado'], 2);
                $recibido = $pago['metodo_pago'] === 'qr'
                    ? $aplicado
                    : round((float) ($pago['monto_recibido'] ?? $aplicado), 2);
                abort_if($recibido < $aplicado, 422, 'En efectivo, el monto recibido no puede ser menor al monto aplicado.');
                return [
                    'metodo_pago' => $pago['metodo_pago'],
                    'monto_aplicado' => $aplicado,
                    'monto_recibido' => $recibido,
                ];
            });

            $totalAplicado = round((float) $partes->sum('monto_aplicado'), 2);
            abort_if($totalAplicado > $saldoActual, 422, 'Los pagos divididos superan el saldo pendiente.');

            $cajaId = null;
            if ($partes->contains(fn ($pago) => $pago['metodo_pago'] === 'efectivo')) {
                $usuarioId = auth('api')->id();
                $caja = Caja::where(function ($query) use ($usuarioId) {
                        $query->where('user_id', $usuarioId)
                            ->orWhereHas('usuarios', fn ($usuarios) => $usuarios->where('users.id', $usuarioId));
                    })
                    ->where('estado', 'abierta')
                    ->lockForUpdate()
                    ->first();
                abort_unless($caja, 422, 'No tienes acceso a una caja abierta para registrar pagos en efectivo.');
                $cajaId = $caja->id;
            }

            $pagos = $partes->map(fn (array $parte) => PagoOrden::create([
                'id_orden' => $orden->id,
                'caja_id' => $parte['metodo_pago'] === 'efectivo' ? $cajaId : null,
                'user_id' => auth('api')->id(),
                'monto_recibido' => $parte['monto_recibido'],
                'monto_pagado' => $parte['monto_aplicado'],
                'cambio_devuelto' => round($parte['monto_recibido'] - $parte['monto_aplicado'], 2),
                'metodo_pago' => $parte['metodo_pago'],
                'tipo_pago' => 'pago',
                'fecha_pago' => now(),
            ]));

            $pagadoTotal = round($pagosAnteriores + $totalAplicado, 2);
            $saldoPendiente = round(max(0, (float) $orden->total - $pagadoTotal), 2);
            $orden->estado_pago = $saldoPendiente <= 0 ? 'completado' : 'parcial';
            if ($orden->tipo_orden === 'delivery') {
                $orden->delivery_monto_esperado = null;
                $orden->delivery_cambio_preparado = false;
                $orden->delivery_cambio_preparado_por = null;
                $orden->delivery_cambio_preparado_en = null;
            }
            $orden->save();

            DB::afterCommit(function () use ($orden, $cajaId) {
                event(new CajaActualizadaEvent((int) ($cajaId ?? 0), 'pago_dividido_registrado'));
                event(ServicioFichaActualizadaEvent::desdeOrden($orden, 'pago_delivery'));
            });

            return response()->json([
                'mensaje' => 'Pagos divididos procesados correctamente.',
                'pagos' => $pagos->map->load('usuario:id,name,username')->values(),
                'total_aplicado' => $totalAplicado,
                'saldo_pendiente' => $saldoPendiente,
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
