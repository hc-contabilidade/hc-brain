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
            ...self::regrasDoHistorico(),
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
