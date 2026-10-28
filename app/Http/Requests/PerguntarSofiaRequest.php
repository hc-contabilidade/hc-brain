<?php

namespace App\Http\Requests;

use App\Support\SofiaAssistant;
use App\Support\SofiaModelos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class PerguntarSofiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pergunta' => ['required', 'string', 'max:1000'],
            ...self::regrasDoModo(),
            ...self::regrasDoHistorico(),
            ...self::regrasDoModelo(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function regrasDoModo(): array
    {
        return [
            'modo' => ['sometimes', 'in:search,base'],
            'conversa_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, list<In|string>>
     */
    public static function regrasDoModelo(): array
    {
        $ids = SofiaModelos::idsDisponiveis();

        return [
            'modelo' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in($ids !== [] ? $ids : ['__nenhum__']),
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function regrasDoHistorico(): array
    {
        return [
            'historico' => ['sometimes', 'array', 'max:'.SofiaAssistant::MAX_HISTORY],
            'historico.*.papel' => ['required', 'in:user,assistant'],
            'historico.*.texto' => ['required', 'string', 'max:4000'],
        ];
    }

    public function pergunta(): string
    {
        return (string) $this->validated('pergunta');
    }

    public function modo(): string
    {
        $modo = $this->validated('modo') ?? 'search';

        return is_string($modo) ? $modo : 'search';
    }

    public function conversaId(): ?string
    {
        $id = $this->validated('conversa_id') ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function modelo(): ?string
    {
        $modelo = $this->validated('modelo') ?? null;

        return is_string($modelo) && $modelo !== '' ? $modelo : SofiaModelos::padrao();
    }

    /**
     * @return list<array{papel: string, texto: string}>
     */
    public function historico(): array
    {
        /** @var list<array{papel: string, texto: string}> $historico */
        $historico = array_values($this->validated('historico') ?? []);

        return $historico;
    }
}
