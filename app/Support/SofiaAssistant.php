<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Loop de Chat Completions da Sofia: persona + histórico + ferramentas.
 * Sem SDK — a mesma fachada Http do Whisper. Sem chave, o chamador responde 503.
 */
final class SofiaAssistant
{
    public const MAX_ROUNDS = 4;

    public const MAX_HISTORY = 20;

    private const FALLBACK = 'Desculpe, não consegui processar agora. Tente de novo em instantes.';

    public static function configurada(): bool
    {
        return filled(config('hc.sofia.chave'));
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $historico
     */
    public static function responder(string $pergunta, array $historico = []): string
    {
        if (! self::configurada()) {
            throw new RuntimeException('A Sofia não está configurada.');
        }

        $messages = [
            ['role' => 'system', 'content' => SofiaPersona::system()],
            ...array_slice($historico, -self::MAX_HISTORY),
            ['role' => 'user', 'content' => $pergunta],
        ];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $choice = self::completar($messages);
            $calls = $choice['tool_calls'] ?? [];

            if (! is_array($calls) || $calls === []) {
                $texto = trim((string) ($choice['content'] ?? ''));

                return $texto !== '' ? $texto : self::FALLBACK;
            }

            $messages[] = $choice;

            foreach ($calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $nome = data_get($call, 'function.name', '');
                $bruto = data_get($call, 'function.arguments', '{}');
                $argumentos = json_decode(is_string($bruto) ? $bruto : '{}', true);
                $resultado = SofiaTools::executar(
                    is_string($nome) ? $nome : '',
                    is_array($argumentos) ? $argumentos : [],
                );

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'content' => $resultado,
                ];
            }
        }

        return 'Não consegui concluir a consulta agora. Tente de novo em instantes.';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private static function completar(array $messages): array
    {
        $resposta = Http::withToken((string) config('hc.sofia.chave'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(45)
            ->post((string) config('hc.sofia.chat.url'), [
                'model' => config('hc.sofia.chat.modelo'),
                'temperature' => 0.3,
                'max_tokens' => 500,
                'messages' => $messages,
                'tools' => SofiaTools::definicoes(),
            ]);

        if ($resposta->failed()) {
            throw new RequestException($resposta);
        }

        $choice = $resposta->json('choices.0.message');

        if (! is_array($choice)) {
            throw new RuntimeException('A consulta à Sofia falhou.');
        }

        return $choice;
    }
}
