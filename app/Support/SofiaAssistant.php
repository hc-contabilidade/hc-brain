<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Loop de Chat Completions da Sofia: persona + histórico + ferramentas.
 * Sem SDK — a mesma fachada Http do Whisper. Sem chave, o chamador responde 503.
 * O modelo escolhido na tela decide provedor (ChatGPT/OpenAI ou Gemini).
 */
final class SofiaAssistant
{
    public const MAX_ROUNDS = 4;

    public const MAX_HISTORY = 20;

    private const FALLBACK = 'Desculpe, não consegui processar agora. Tente de novo em instantes.';

    public static function configurada(): bool
    {
        return SofiaModelos::algumDisponivel();
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $historico
     * @param  (callable(string): void)|null  $onToken
     */
    public static function responder(
        string $pergunta,
        array $historico = [],
        ?callable $onToken = null,
        ?string $modeloId = null,
    ): string {
        if (! self::configurada()) {
            throw new RuntimeException('A Sofia não está configurada.');
        }

        $modelo = SofiaModelos::resolver($modeloId);

        $messages = [
            ['role' => 'system', 'content' => SofiaPersona::system()],
            ...array_slice($historico, -self::MAX_HISTORY),
            ['role' => 'user', 'content' => $pergunta],
        ];

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $choice = self::completar($messages, $onToken, $modelo);
            $calls = $choice['tool_calls'];

            if ($calls === []) {
                $texto = trim($choice['content']);

                return $texto !== '' ? $texto : self::FALLBACK;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $choice['content'] !== '' ? $choice['content'] : null,
                'tool_calls' => $calls,
            ];

            foreach ($calls as $call) {
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
     * @param  (callable(string): void)|null  $onToken
     * @param  array{api: string, chave: string, url: string}  $modelo
     * @return array{content: string, tool_calls: list<array<string, mixed>>}
     */
    private static function completar(array $messages, ?callable $onToken, array $modelo): array
    {
        $corpo = [
            'model' => $modelo['api'],
            'temperature' => 0.3,
            'max_tokens' => 1200,
            'messages' => $messages,
            'tools' => SofiaTools::definicoes(),
        ];

        if ($onToken !== null) {
            return self::completarStream($corpo, $onToken, $modelo);
        }

        $resposta = self::cliente($modelo)->post($modelo['url'], $corpo);

        if ($resposta->failed()) {
            throw new RequestException($resposta);
        }

        $choice = $resposta->json('choices.0.message');

        if (! is_array($choice)) {
            throw new RuntimeException('A consulta à Sofia falhou.');
        }

        $calls = $choice['tool_calls'] ?? [];

        return [
            'content' => (string) ($choice['content'] ?? ''),
            'tool_calls' => is_array($calls) ? array_values($calls) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @param  callable(string): void  $onToken
     * @param  array{chave: string, url: string}  $modelo
     * @return array{content: string, tool_calls: list<array<string, mixed>>}
     */
    private static function completarStream(array $corpo, callable $onToken, array $modelo): array
    {
        $resposta = self::cliente($modelo)
            ->withOptions(['stream' => true])
            ->post($modelo['url'], [...$corpo, 'stream' => true]);

        if ($resposta->failed()) {
            throw new RequestException($resposta);
        }

        $body = $resposta->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';
        $calls = [];
        $viuFerramenta = false;

        while (! $body->eof()) {
            $buffer .= $body->read(512);

            while (($quebra = strpos($buffer, "\n")) !== false) {
                $linha = trim(substr($buffer, 0, $quebra));
                $buffer = substr($buffer, $quebra + 1);

                if ($linha === '' || ! str_starts_with($linha, 'data:')) {
                    continue;
                }

                $data = trim(substr($linha, 5));
                if ($data === '[DONE]') {
                    break 2;
                }

                $json = json_decode($data, true);
                $delta = is_array($json) ? ($json['choices'][0]['delta'] ?? null) : null;
                if (! is_array($delta)) {
                    continue;
                }

                $pedaco = $delta['content'] ?? null;
                if (is_string($pedaco) && $pedaco !== '') {
                    $content .= $pedaco;
                    if (! $viuFerramenta) {
                        $onToken($pedaco);
                    }
                }

                foreach ($delta['tool_calls'] ?? [] as $callDelta) {
                    if (! is_array($callDelta)) {
                        continue;
                    }

                    $viuFerramenta = true;
                    $indice = (int) ($callDelta['index'] ?? 0);
                    if (! isset($calls[$indice])) {
                        $calls[$indice] = [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }

                    if (isset($callDelta['id']) && is_string($callDelta['id'])) {
                        $calls[$indice]['id'] = $callDelta['id'];
                    }

                    $funcao = $callDelta['function'] ?? [];
                    if (is_array($funcao)) {
                        if (isset($funcao['name']) && is_string($funcao['name'])) {
                            $calls[$indice]['function']['name'] .= $funcao['name'];
                        }
                        if (isset($funcao['arguments']) && is_string($funcao['arguments'])) {
                            $calls[$indice]['function']['arguments'] .= $funcao['arguments'];
                        }
                    }
                }
            }
        }

        ksort($calls);

        return [
            'content' => $content,
            'tool_calls' => array_values($calls),
        ];
    }

    /**
     * @param  array{chave: string}  $modelo
     */
    private static function cliente(array $modelo): PendingRequest
    {
        return Http::withToken($modelo['chave'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(60);
    }
}
