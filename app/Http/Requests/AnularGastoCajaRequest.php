<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnularGastoCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    public function rules(): array
    {
        return ['motivo_anulacion' => ['required', 'string', 'max:500']];
    }
}
