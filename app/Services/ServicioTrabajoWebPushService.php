<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\WebPushSubscription as StoredSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class ServicioTrabajoWebPushService
{
    private const ESTADOS_LISTOS = ['listo_para_recoger', 'recogido', 'servido'];

    public function syncForOrder(int $orderId): void
    {
        $meseroId = Orden::whereKey($orderId)->value('mesero_id');
        if ($meseroId) $this->syncForUser((int) $meseroId);
    }

    public function syncForUser(int $userId): void
    {
        $vapid = config('webpush.vapid');
        if (empty($vapid['public_key']) || empty($vapid['private_key']) || empty($vapid['subject'])) return;

        $devices = StoredSubscription::query()
            ->where('station_code', 'SERVICIO')
            ->where('user_id', $userId)
            ->get();
        if ($devices->isEmpty()) return;

        $orders = Orden::with([
            'cliente:id,nombre',
            'mesa:id,numero',
            'detalles:id,orden_id,producto_id,cantidad,estado_cocina',
            'detalles.producto:id,nombre',
            'detalles.estadosEstacion:id,orden_detalle_id,estado',
        ])->operativas()
            ->deFechaOperativa(now()->toDateString())
            ->where('mesero_id', $userId)
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->orderBy('tomada_en')
            ->orderBy('id')
            ->limit(2)
            ->get();

        $tag = 'servicio-trabajo-'.$userId;
        $notification = $orders->isEmpty()
            ? ['close' => true, 'tag' => $tag]
            : $this->notification($orders, $tag);

        $this->send($devices, $notification, $vapid, 'svc-work-'.$userId);
    }

    /** @param Collection<int, Orden> $orders */
    private function notification(Collection $orders, string $tag): array
    {
        $primary = $orders->first();
        if ($orders->count() === 1) {
            $progress = $this->progress($primary);
            $readyNames = $primary->detalles
                ->filter(fn ($detail) => $this->isReady($detail))
                ->pluck('producto.nombre')->filter()->unique()->take(2)->implode(', ');
            $body = trim((string) $primary->cliente?->nombre) ?: 'Cliente no registrado';
            $body .= ' · '.$progress['ready'].'/'.$progress['total'].' listos';
            if ($readyNames !== '') $body .= ' · Listo: '.$readyNames;

            return $this->baseNotification(
                'Ficha #'.($primary->numero_orden ?: $primary->id).' · '.$this->destination($primary),
                $body,
                $tag,
                (int) $primary->id,
            );
        }

        $body = $orders->map(function (Orden $order) {
            $progress = $this->progress($order);
            return '#'.($order->numero_orden ?: $order->id).' '.$this->destination($order)
                .': '.$progress['ready'].'/'.$progress['total'].' listos';
        })->implode("\n");

        return $this->baseNotification('Mi trabajo · '.$orders->count().' fichas', $body, $tag, (int) $primary->id);
    }

    private function baseNotification(string $title, string $body, string $tag, int $orderId): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'url' => '/servicio?ficha='.$orderId,
            'matchPath' => '/servicio',
            'icon' => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-96x96.png',
            'tag' => $tag,
            'renotify' => false,
            'requireInteraction' => true,
            'silent' => true,
            'showWhenVisible' => true,
            'ordenId' => $orderId,
        ];
    }

    private function progress(Orden $order): array
    {
        return [
            'ready' => $order->detalles->filter(fn ($detail) => $this->isReady($detail))->sum('cantidad'),
            'total' => $order->detalles->sum('cantidad'),
        ];
    }

    private function isReady($detail): bool
    {
        return $detail->estadosEstacion->isNotEmpty()
            ? $detail->estadosEstacion->every(fn ($state) => in_array($state->estado, self::ESTADOS_LISTOS, true))
            : in_array($detail->estado_cocina, self::ESTADOS_LISTOS, true);
    }

    private function destination(Orden $order): string
    {
        return match ($order->tipo_orden) {
            'delivery' => 'Delivery',
            'to-go' => 'Para llevar',
            default => $order->mesa ? 'Mesa '.$order->mesa->numero : 'Mesa pendiente',
        };
    }

    private function send(Collection $devices, array $notification, array $vapid, string $topic): void
    {
        $payload = json_encode(['tonitoNotification' => $notification], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $vapid['subject'],
                    'publicKey' => $vapid['public_key'],
                    'privateKey' => $vapid['private_key'],
                ],
            ], ['TTL' => 86400, 'urgency' => 'normal', 'topic' => $topic]);

            foreach ($devices as $device) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $device->endpoint,
                    'publicKey' => $device->public_key,
                    'authToken' => $device->auth_token,
                    'contentEncoding' => $device->content_encoding,
                ]), $payload);
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) continue;
                $endpoint = $report->getRequest()->getUri()->__toString();
                if ($report->isSubscriptionExpired()) {
                    StoredSubscription::where('endpoint_hash', hash('sha256', $endpoint))->delete();
                    continue;
                }
                Log::warning('No se pudo actualizar el resumen de trabajo del mesero.', [
                    'user_id' => $devices->first()?->user_id,
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Falló el resumen de trabajo del mesero.', [
                'user_id' => $devices->first()?->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
