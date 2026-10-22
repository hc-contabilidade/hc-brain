<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class EnviarAudioSofiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:'.config('hc.sofia.audio_kb'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $extensao = strtolower($value->getClientOriginalExtension());
                    $mime = strtolower((string) $value->getClientMimeType());
                    $extensaoAceita = in_array($extensao, config('hc.sofia.extensoes'), true);
                    $mimeAceito = $mime === ''
                        || str_starts_with($mime, 'audio/')
                        || $mime === 'video/webm'
                        || $mime === 'application/octet-stream';

                    if (! $extensaoAceita || ! $mimeAceito) {
                        $fail('Envie um áudio em webm, mp3, m4a, wav ou ogg.');
                    }
                },
            ],
            ...PerguntarSofiaRequest::regrasDoModo(),
            ...PerguntarSofiaRequest::regrasDoHistorico(),
        ];
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

    public function messages(): array
    {
        return [
            'audio.required' => 'Escolha ou grave um áudio para enviar.',
            'audio.file' => 'Escolha ou grave um áudio para enviar.',
            'audio.max' => 'O áudio passa do limite de :max KB.',
        ];
    }
}
