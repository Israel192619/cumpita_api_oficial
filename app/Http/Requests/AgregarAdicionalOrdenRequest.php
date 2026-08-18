<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgregarAdicionalOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return mb_strtolower($this->user()?->role?->nombre ?? '') === 'mesero';
    }

    public function rules(): array
    {
        return [
            'producto_id' => ['required', 'integer', 'exists:productos,id'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:20'],
            'nota' => ['nullable', 'string', 'max:255'],
            'modificador_opcion_ids' => ['nullable', 'array'],
            'modificador_opcion_ids.*' => ['integer', 'distinct', 'exists:modificador_opciones,id'],
        ];
    }
}
