<?php

namespace App\Http\Requests;

use App\Models\MarketingIdeia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarMarketingIdeiaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:5000'],
            'plataforma' => ['required', Rule::in(MarketingIdeia::PLATAFORMAS)],
            'status' => ['required', Rule::in(MarketingIdeia::STATUS)],
            'responsavel' => ['nullable', 'string', 'max:255'],
            'prioridade' => ['required', Rule::in(MarketingIdeia::PRIORIDADES)],
        ];
    }

    /** @return array<string, mixed> */
    public function paraOBanco(): array
    {
        return [
            'titulo' => $this->string('titulo')->toString(),
            'descricao' => $this->input('descricao'),
            'plataforma' => $this->string('plataforma')->toString(),
            'status' => $this->string('status')->toString(),
            'responsavel' => $this->input('responsavel'),
            'prioridade' => $this->string('prioridade')->toString(),
        ];
    }
}
