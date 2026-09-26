<?php

namespace App\Services;

use App\Events\OrdenCreadaEvent;
use App\Events\PreordenActualizadaEvent;
use App\Jobs\SendGrillOrderPush;
use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PreordenActivationService
{
    public function activar(int $ordenId, ?int $usuarioId = null): Orden
    {
        $orden = DB::transaction(function () use ($ordenId, $usuarioId) {
            $orden = Orden::with([
                'detalles.opciones.modificadorOpcion.modificador',
                'detalles.estadosEstacion',
            ])->lockForUpdate()->findOrFail($ordenId);

            abort_unless($orden->tipo_flujo === 'preorden', 422, 'La orden seleccionada no es una preorden.');
            abort_if($orden->estado_preorden === 'activada', 409, 'La preorden ya fue activada.');
            abort_if($orden->estado_solicitud === 'pendiente', 422, 'La solicitud debe ser aceptada antes de activar la preorden.');
            abort_if($orden->estado_preorden === 'cancelada', 422, 'Una preorden cancelada no puede activarse.');

            $orden->update([
                'estado_preorden' => 'activada',
                'preorden_activada_en' => now(),
                'preorden_activada_por' => $usuarioId,
                'fecha_orden' => now(),
                'estado' => 'pendiente',
            ]);

            $kds = app(KdsEstacionService::class);
            foreach ($orden->detalles as $detalle) {
                $detalle->unsetRelation('estadosEstacion');
                $kds->sincronizarDetalle($detalle);
            }

            return $orden->fresh(['cliente', 'mesa', 'detalles.producto', 'detalles.estadosEstacion']);
        });

        $this->publicarActivacion($orden);
        return $orden;
    }

    /** Activa deliveries al entrar en su ventana operativa de tres minutos. */
    public function activarDeliveriesProximos(): void
    {
        $ids = Orden::query()
            ->where('tipo_flujo', 'preorden')
            ->where('estado_preorden', 'programada')
            ->where('tipo_orden', 'delivery')
            ->where(fn ($query) => $query->whereNull('estado_solicitud')->orWhere('estado_solicitud', 'aceptada'))
            ->whereNotNull('fecha_programada')
            ->where('fecha_programada', '<=', now()->addMinutes(3))
            ->whereDate('fecha_programada', now()->toDateString())
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $this->activar((int) $id);
            } catch (\Throwable $e) {
                Log::warning('No se pudo activar automáticamente la preorden delivery.', [
                    'orden_id' => (int) $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function publicarActivacion(Orden $orden): void
    {
        try {
            event(new PreordenActualizadaEvent($orden, 'preorden_activada'));
            event(new OrdenCreadaEvent($orden));
            SendGrillOrderPush::dispatch($orden->id)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('La preorden fue activada, pero no se pudo publicar el aviso.', [
                'orden_id' => $orden->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
