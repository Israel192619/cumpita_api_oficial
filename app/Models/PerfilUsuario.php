<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfilUsuario extends Model
{
    protected $fillable = [
        'direccion',
        'numero_celular',
        'avatar'
    ];
    protected $appends = ['avatar_url'];

    public function user(){
        return $this->BelongsTo(User::class);
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar
            ? asset('storage/' . $this->avatar)
            : null;
    }

}
