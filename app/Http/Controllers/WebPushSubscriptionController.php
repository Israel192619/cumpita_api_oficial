<?php

namespace App\Http\Controllers;

use App\Models\WebPushSubscription;
use App\Services\ServicioTrabajoWebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function publicKey(): JsonResponse
    {
        $key = (string) config('webpush.vapid.public_key');
        abort_if($key === '', 503, 'Las notificaciones todavía no están configuradas.');

        return response()->json(['public_key' => $key]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'string', 'in:PARRILLA,SERVICIO'],
            'endpoint' => ['required', 'string', 'max:4096'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:1024'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ]);
        // Compatibilidad con la primera versión PWA, que solo registraba Parrilla
        // y todavía puede permanecer en la caché de algunos Android.
        $channel = $data['channel'] ?? 'PARRILLA';
        $role = mb_strtolower(trim((string) $request->user('api')?->role?->nombre));
        $allowed = $channel === 'PARRILLA'
            ? ['admin', 'administrador', 'gerente', 'cocinero', 'cocina', 'parrilla']
            : ['admin', 'administrador', 'gerente', 'mesero', 'despacho'];
        abort_unless(in_array($role, $allowed, true), 403, 'No tienes permiso para activar avisos de esta estación.');

        $subscription = WebPushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => auth('api')->id(),
                'station_code' => $channel,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'last_seen_at' => now(),
            ]
        );

        if ($channel === 'SERVICIO' && $subscription->user_id) {
            app()->terminating(fn () => app(ServicioTrabajoWebPushService::class)->syncForUser((int) $subscription->user_id));
        }

        return response()->json(['active' => true, 'id' => $subscription->id]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:4096']]);
        WebPushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['active' => false]);
    }
}
