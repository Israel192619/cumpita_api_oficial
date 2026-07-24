<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\PagoOrden;
use App\Models\Cliente;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrdenController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $ordenes = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto', 'detalles.opciones.modificadorOpcion')->get();
        return response()->json([
            'ordenes' => $ordenes
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     * Soporta creación desde POS con cliente_nombre y cliente_telefono
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'nullable|exists:clientes,id',
            'cliente_nombre' => 'nullable|string|max:255',
            'cliente_telefono' => 'nullable|string|max:50',
            'mesa_id' => 'nullable|exists:mesas,id',
            'tipo_orden' => 'nullable|in:dine-in,to-go,delivery',
            'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'fecha_reserva' => 'nullable|date_format:Y-m-d\TH:i:s',
            'subtotal' => 'required|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'observaciones' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);

        

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$request->cliente_id && !$request->cliente_nombre) {
            return response()->json(['message' => 'El cliente es obligatorio para crear una orden.'], 422);
        }

        DB::beginTransaction();

        try {
            $userActual = auth('api')->user();
            
            // Obtener cliente existente o crear uno nuevo
            $clienteId = null;
            
            // Prioritario: cliente_id si se proporciona
            if ($request->cliente_id) {
                $clienteId = $request->cliente_id;
            }
            // Si no, crear/buscar por nombre
            elseif ($request->cliente_nombre) {
                $cliente = Cliente::firstOrCreate(
                    ['nombre' => $request->cliente_nombre],
                    ['telefono' => $request->cliente_telefono]
                );
                $clienteId = $cliente->id;
            }

            $fechaOrden = $request->filled('fecha_orden')
                ? Carbon::createFromFormat('Y-m-d\TH:i:s', $request->fecha_orden)
                : now();

            $ultimoNumero = Orden::whereDate(
                'fecha_orden',
                $fechaOrden->toDateString()
            )->max('numero_orden');

            $numeroOrden = ($ultimoNumero ?? 0) + 1;

            // Crear la orden
            $orden = Orden::create([
                'user_id' => $userActual->id,
                'cliente_id' => $clienteId,
                'mesa_id' => $request->mesa_id,
                'numero_orden' => $numeroOrden,
                'fecha_orden' => $request->filled('fecha_orden') ? $request->fecha_orden : null,
                'fecha_reserva' => $request->filled('fecha_reserva') ? $request->fecha_reserva : null,
                'subtotal' => $request->subtotal,
                'descuento' => $request->descuento ?? 0,
                'total' => $request->total,
                'estado' => 'pendiente',
                'observaciones' => $request->observaciones,
                'tipo_orden' => $request->tipo_orden ?? 'dine-in',
            ]);

            // Crear detalles de la orden (items del carrito)
            foreach ($request->items as $item) {
                $producto = \App\Models\Producto::findOrFail($item['producto_id']);

                if ($producto->maneja_stock && $producto->stock !== null) {
                    $cantidadSolicitada = (int) $item['cantidad'];
                    if ((int) $producto->stock < $cantidadSolicitada) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'No hay suficiente stock para el producto ' . $producto->nombre . '.'
                        ], 422);
                    }

                    $producto->stock = max(0, (int) $producto->stock - $cantidadSolicitada);
                    $producto->save();
                }

                $ordenDetalle = OrdenDetalle::create([
                    'orden_id' => $orden->id,
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'nota' => $item['nota'] ?? null,
                ]);

                // Crear opciones del detalle (modificadores)
                if (isset($item['modificadores']) && is_array($item['modificadores'])) {
                    foreach ($item['modificadores'] as $modificador) {
                        OrdenDetalleOpcion::create([
                            'orden_detalle_id' => $ordenDetalle->id,
                            'modificador_opcion_id' => $modificador['modificador_opcion_id'],
                            'precio_extra' => $modificador['precio_extra'] ?? 0,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Orden creada exitosamente',
                'orden' => $orden->load('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.opciones.modificadorOpcion')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $orden = Orden::with('user', 'cliente', 'mesa', 'pagos', 'detalles.producto', 'detalles.opciones.modificadorOpcion')->findOrFail($id);
        
        if (!$orden) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        return response()->json([
            'orden' => $orden
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id' => 'nullable|exists:clientes,id',
            'cliente_nombre' => 'nullable|string|max:255',
            'cliente_telefono' => 'nullable|string|max:50',
            'mesa_id' => 'nullable|exists:mesas,id',
            'tipo_orden' => 'nullable|in:dine-in,to-go,delivery',
            // 'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'fecha_orden' => 'nullable|date_format:Y-m-d\TH:i:s',
            'fecha_reserva' => 'nullable|date_format:Y-m-d\TH:i:s',
            'subtotal' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'total' => 'nullable|numeric|min:0',
            'estado' => 'nullable|in:pendiente,preparando,listo,entregado,cancelado',
            'observaciones' => 'nullable|string',
            'items' => 'nullable|array|min:1',
            'items.*.producto_id' => 'required_with:items|exists:productos,id',
            'items.*.cantidad' => 'required_with:items|integer|min:1',
            'items.*.precio_unitario' => 'required_with:items|numeric|min:0',
            'items.*.nota' => 'nullable|string|max:255',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $orden = Orden::findOrFail($id);

            $clienteId = $orden->cliente_id;
            if ($request->has('cliente_id')) {
                $clienteId = $request->cliente_id;
            } elseif ($request->filled('cliente_nombre')) {
                $cliente = Cliente::firstOrCreate(
                    ['nombre' => $request->cliente_nombre],
                    ['telefono' => $request->cliente_telefono]
                );
                $clienteId = $cliente->id;
            }

            $updateData = [];
            if ($request->has('cliente_id') || $request->filled('cliente_nombre')) {
                $updateData['cliente_id'] = $clienteId;
            }
            if ($request->has('mesa_id')) {
                $updateData['mesa_id'] = $request->mesa_id;
            }
            if ($request->has('tipo_orden')) {
                $updateData['tipo_orden'] = $request->tipo_orden;
            }
            if ($request->has('fecha_orden')) {
                $updateData['fecha_orden'] = $request->filled('fecha_orden') ? $request->fecha_orden : null;
            }
            if ($request->has('fecha_reserva')) {
                $updateData['fecha_reserva'] = $request->filled('fecha_reserva') ? $request->fecha_reserva : null;
            }
            if ($request->has('subtotal')) {
                $updateData['subtotal'] = $request->subtotal;
            }
            if ($request->has('descuento')) {
                $updateData['descuento'] = $request->descuento ?? 0;
            }
            if ($request->has('total')) {
                $updateData['total'] = $request->total;
            }
            if ($request->has('estado')) {
                $updateData['estado'] = $request->estado;
            }
            if ($request->has('observaciones')) {
                $updateData['observaciones'] = $request->observaciones;
            }

            if (!empty($updateData)) {
                $orden->update($updateData);
            }

            if ($request->has('items')) {
                $this->syncOrderItems($orden, $request->items);
            }

            $pagosTotales = PagoOrden::where('id_orden', $orden->id)->sum('monto_pagado');
            $orden->estado_pago = $pagosTotales <= 0
                ? 'pendiente'
                : ($pagosTotales < (float) $orden->total ? 'parcial' : 'completado');
            $orden->save();

            DB::commit();

            return response()->json([
                'message' => 'Orden actualizada exitosamente',
                'orden' => $orden->load('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.opciones.modificadorOpcion')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'No hay suficiente stock')) {
                return response()->json([
                    'message' => $e->getMessage()
                ], 422);
            }

            return response()->json([
                'message' => 'Error al actualizar la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function syncOrderItems(Orden $orden, array $items): void
    {
        foreach ($orden->detalles as $detalle) {
            $producto = \App\Models\Producto::find($detalle->producto_id);
            if ($producto && $producto->maneja_stock && $producto->stock !== null) {
                $producto->stock = (int) $producto->stock + (int) $detalle->cantidad;
                $producto->save();
            }
        }

        $orden->detalles()->get()->each(function (OrdenDetalle $detalle) {
            $detalle->opciones()->delete();
        });

        $orden->detalles()->delete();

        foreach ($items as $item) {
            $producto = \App\Models\Producto::findOrFail($item['producto_id']);
            $cantidadSolicitada = (int) ($item['cantidad'] ?? 1);

            if ($producto->maneja_stock && $producto->stock !== null) {
                if ((int) $producto->stock < $cantidadSolicitada) {
                    throw new \RuntimeException('No hay suficiente stock para el producto ' . $producto->nombre . '.');
                }

                $producto->stock = max(0, (int) $producto->stock - $cantidadSolicitada);
                $producto->save();
            }

            $ordenDetalle = OrdenDetalle::create([
                'orden_id' => $orden->id,
                'producto_id' => $item['producto_id'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'nota' => $item['nota'] ?? null,
            ]);

            if (isset($item['modificadores']) && is_array($item['modificadores'])) {
                foreach ($item['modificadores'] as $modificador) {
                    OrdenDetalleOpcion::create([
                        'orden_detalle_id' => $ordenDetalle->id,
                        'modificador_opcion_id' => $modificador['modificador_opcion_id'],
                        'precio_extra' => $modificador['precio_extra'] ?? 0,
                    ]);
                }
            }
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();

        try {
            $orden = Orden::findOrFail($id);

            if (!$orden) {
                return response()->json(['message' => 'Orden no encontrada'], 404);
            }

            foreach ($orden->detalles as $detalle) {
                $producto = \App\Models\Producto::find($detalle->producto_id);
                if ($producto && $producto->maneja_stock && $producto->stock !== null) {
                    $producto->stock = (int) $producto->stock + (int) $detalle->cantidad;
                    $producto->save();
                }
            }

            $orden->delete();
            DB::commit();

            return response()->json([
                'message' => 'Orden eliminada exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar la orden',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
