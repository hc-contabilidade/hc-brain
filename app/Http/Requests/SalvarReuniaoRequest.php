<?php

namespace App\Http\Requests;

use App\Models\Reuniao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarReuniaoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'empresaId' => ['nullable', 'required_unless:tipo,'.Reuniao::INTERNA, 'string', 'exists:empresas,id'],
            'tipo' => ['required', Rule::in(Reuniao::TIPOS)],
            'data' => ['required', 'date'],
            'horario' => ['nullable', 'date_format:H:i'],
            'responsavel' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(Reuniao::STATUS)],
            'resumo' => ['required', 'string', 'max:2000'],

            // As três listas são opcionais: uma reunião recém-agendada ainda
            // não tem decisão nem próximo passo registrado.
            'participantes' => ['array'],
            'participantes.*' => ['string', 'max:255'],
            'decisoes' => ['array'],
            'decisoes.*' => ['string', 'max:500'],
            'proximosPassos' => ['array'],
            'proximosPassos.*' => ['string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'empresaId' => 'empresa',
            'proximosPassos' => 'próximos passos',
        ];
    }

    public function messages(): array
    {
        return [
            'empresaId.exists' => 'Escolha uma empresa da carteira.',
            'empresaId.required_unless' => 'Escolha a empresa: só reunião interna fica sem empresa.',
            'horario.date_format' => 'Informe o horário como 14:30.',
        ];
    }

    /** Os campos já no formato das colunas da tabela. */
    public function paraOBanco(): array
    {
        return [
            'empresa_id' => $this->filled('empresaId') ? $this->string('empresaId')->toString() : null,
            'tipo' => $this->string('tipo')->toString(),
            'data' => $this->date('data'),
            'horario' => $this->input('horario'),
            'responsavel' => $this->string('responsavel')->toString(),
            'status' => $this->string('status')->toString(),
            'resumo' => $this->string('resumo')->toString(),
            'participantes' => array_values($this->array('participantes')),
            'decisoes' => array_values($this->array('decisoes')),
            'proximos_passos' => array_values($this->array('proximosPassos')),
        ];
    }
}
