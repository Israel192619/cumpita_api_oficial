<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'station_code',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
        'last_seen_at',
    ];

    protected $casts = ['last_seen_at' => 'datetime'];
}
