<?php

namespace App\Http\Controllers;

use App\Models\Orden;

class HistorialCambioOrdenController extends Controller
{
    public function index(Orden $orden)
    {
        $historial = $orden->historialCambios()
            ->with([
                'user:id,name,username',
                'producto:id,nombre',
                'detalle:id,orden_id,producto_id,cantidad',
            ])
            ->latest()
            ->get();

        return response()->json(['historial' => $historial]);
    }
}
