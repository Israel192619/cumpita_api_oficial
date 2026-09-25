<?php

namespace App\Services;

use App\Models\Orden;
use App\Models\WebPushSubscription as StoredSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class GrillWebPushService
{
    public function sendForOrder(int $orderId): void
    {
        $vapid = config('webpush.vapid');
        if (empty($vapid['public_key']) || empty($vapid['private_key']) || empty($vapid['subject'])) return;

        $order = Orden::with(['cliente', 'mesa', 'detalles.producto.categoria'])->find($orderId);
        if (!$order || $order->esPreordenProgramada()) return;

        $products = [];
        foreach ($order->detalles as $detail) {
            $product = $detail->producto;
            if (!$product) continue;
            $category = mb_strtolower((string) $product->categoria?->nombre);
            $name = mb_strtolower((string) $product->nombre);
            if (!str_contains($category, 'pescad') && !str_contains($name, 'pescad')) continue;

            $price = (float) $detail->precio_unitario;
            $key = $product->id.'|'.number_format($price, 2, '.', '');
            $products[$key] ??= ['name' => $product->nombre, 'quantity' => 0, 'price' => $price];
            $products[$key]['quantity'] += (int) $detail->cantidad;
        }
        if ($products !== []) {
            $body = collect($products)->map(fn (array $product) => sprintf(
                '%d× %s · Bs %s',
                $product['quantity'],
                $product['name'],
                number_format($product['price'], 2, ',', '.')
            ))->implode("\n");
            $this->send('PARRILLA', $order->id, 'orden-parrilla-'.$order->id, [
                'title' => 'Nuevo pedido para Parrilla',
                'body' => $body,
                'url' => '/cocina/parrilla?alerta='.$order->id,
                'matchPath' => '/cocina/parrilla',
            ], $vapid);
        }

        $destination = match ($order->tipo_orden) {
            'delivery' => 'Delivery',
            'to-go' => 'Para llevar',
            default => $order->mesa ? 'Mesa '.$order->mesa->numero : 'Mesa pendiente',
        };
        $client = trim((string) $order->cliente?->nombre) ?: 'Cliente no registrado';
        $this->send('SERVICIO', $order->id, 'orden-servicio-'.$order->id, [
            'title' => 'Nueva ficha para tomar',
            'body' => sprintf('Ficha #%s · %s · %s', $order->numero_orden ?: $order->id, $client, $destination),
            'url' => '/servicio',
            'matchPath' => '/servicio',
        ], $vapid);
    }

    /** @param array{title:string,body:string,url:string,matchPath:string} $notification */
    private function send(string $channel, int $orderId, string $topic, array $notification, array $vapid): void
    {
        $devices = StoredSubscription::where('station_code', $channel)->get();
        if ($devices->isEmpty()) return;

        $payload = json_encode([
            'tonitoNotification' => $notification + [
                'icon' => '/icons/icon-192x192.png',
                'badge' => '/icons/icon-96x96.png',
                'tag' => $topic,
                'renotify' => true,
                'requireInteraction' => true,
                'silent' => false,
                'vibrate' => [300, 120, 300, 120, 650],
                'ordenId' => $orderId,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $vapid['subject'],
                    'publicKey' => $vapid['public_key'],
                    'privateKey' => $vapid['private_key'],
                ],
            ], ['TTL' => 300, 'urgency' => 'high', 'topic' => $topic]);

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
                Log::warning('No se pudo enviar una notificación Web Push a Parrilla.', [
                    'orden_id' => $orderId,
                    'channel' => $channel,
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Falló el envío Web Push a Parrilla.', [
                'orden_id' => $orderId,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
