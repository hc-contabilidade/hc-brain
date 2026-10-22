<?php

namespace App\Http\Requests;

use App\Support\SofiaAssistant;
use Illuminate\Foundation\Http\FormRequest;

class PerguntarSofiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pergunta' => ['required', 'string', 'max:1000'],
            ...self::regrasDoModo(),
            ...self::regrasDoHistorico(),
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
