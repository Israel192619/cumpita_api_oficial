<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AjusteStock extends Model
{
    protected $table = 'ajustes_stock';

    protected $fillable = [
        'producto_id', 'tipo', 'cantidad', 'stock_anterior', 'stock_final', 'motivo', 'usuario_id', 'revertido_por_ajuste_id',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }

    public function reversion()
    {
        return $this->belongsTo(self::class, 'revertido_por_ajuste_id');
    }
}
