<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGastoCajaRequest extends FormRequest
{
    public const CATEGORIAS = [
        'INSUMOS', 'LIMPIEZA', 'GAS', 'CARBON', 'TRANSPORTE',
        'MANTENIMIENTO', 'SERVICIOS', 'PERSONAL', 'OTROS',
    ];

    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return [
            'categoria' => ['required', Rule::in(self::CATEGORIAS)],
            'concepto' => ['nullable', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
