<?php

namespace App\Http\Controllers;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\OrdenDetalleOpcion;
use App\Models\Cliente;
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
        $ordenes = Orden::with('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.opciones.modificadorOpcion')->get();
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
            // 'cliente_nombre' => 'nullable|string|max:255',
            // 'cliente_telefono' => 'nullable|string|max:20',
            'mesa_id' => 'nullable|exists:mesas,id',
            'tipo_orden' => 'nullable|in:dine-in,to-go,delivery',
            'subtotal' => 'required|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'metodo_pago' => 'required|in:efectivo,qr,tarjeta',
            'observaciones' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'required|exists:productos,id',
            'items.*.cantidad' => 'required|integer|min:1',
            'items.*.precio_unitario' => 'required|numeric|min:0',
            'items.*.modificadores' => 'nullable|array',
            'items.*.modificadores.*.modificador_opcion_id' => 'required_with:items.*.modificadores|exists:modificador_opciones,id',
            'items.*.modificadores.*.precio_extra' => 'required_with:items.*.modificadores|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
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

            // Crear la orden
            $orden = Orden::create([
                'user_id' => $userActual->id,
                'cliente_id' => $clienteId,
                'mesa_id' => $request->mesa_id,
                'fecha_orden' => now()->toDateTimeString(),
                'subtotal' => $request->subtotal,
                'descuento' => $request->descuento ?? 0,
                'total' => $request->total,
                'estado' => 'pendiente',
                'metodo_pago' => $request->metodo_pago,
                'observaciones' => $request->observaciones,
                'tipo_orden' => $request->tipo_orden ?? 'dine-in',
            ]);

            // Crear detalles de la orden (items del carrito)
            foreach ($request->items as $item) {
                $ordenDetalle = OrdenDetalle::create([
                    'orden_id' => $orden->id,
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
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
        $orden = Orden::with('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.opciones.modificadorOpcion')->findOrFail($id);
        
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
            'estado' => 'nullable|in:pendiente,preparando,listo,entregado,pagado,cancelado',
            'metodo_pago' => 'nullable|in:efectivo,qr,tarjeta',
            'observaciones' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $orden = Orden::findOrFail($id);

            if (!$orden) {
                return response()->json(['message' => 'Orden no encontrada'], 404);
            }

            // Actualizar solo los campos permitidos
            $updateData = [];
            if ($request->has('estado')) {
                $updateData['estado'] = $request->estado;
            }
            if ($request->has('metodo_pago')) {
                $updateData['metodo_pago'] = $request->metodo_pago;
            }
            if ($request->has('observaciones')) {
                $updateData['observaciones'] = $request->observaciones;
            }

            if (!empty($updateData)) {
                $orden->update($updateData);
            }

            DB::commit();

            return response()->json([
                'message' => 'Orden actualizada exitosamente',
                'orden' => $orden->load('user', 'cliente', 'mesa', 'detalles.producto', 'detalles.opciones.modificadorOpcion')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar la orden',
                'error' => $e->getMessage()
            ], 500);
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
