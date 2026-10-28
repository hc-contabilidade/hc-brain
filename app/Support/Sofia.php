<?php

namespace App\Support;

use App\Models\SofiaConversa;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * RESPOSTAS DA SOFIA
 * A Sofia lê a mesma base das outras telas por ferramentas Eloquent e
 * redige a resposta com Chat Completions. A tela manda a pergunta (o
 * modo e, se houver, o chat) e mostra o texto que volta.
 */
final class Sofia
{
    public static function configurada(): bool
    {
        return SofiaAssistant::configurada();
    }

    /**
     * @param  list<array{papel?: string, texto?: string}>  $historico
     * @param  (callable(string): void)|null  $onToken
     */
    public static function responder(
        string $pergunta,
        array $historico = [],
        ?callable $onToken = null,
        ?string $modelo = null,
    ): string {
        return SofiaAssistant::responder(
            $pergunta,
            self::historicoParaModelo($historico),
            $onToken,
            $modelo,
        );
    }

    /**
     * @param  list<array{papel?: string, texto?: string}>  $historico
     * @param  (callable(string): void)|null  $onToken
     * @return array{resposta: string, conversa: SofiaConversa}
     */
    public static function responderNaConversa(
        User $usuario,
        string $pergunta,
        string $modo,
        ?string $conversaId,
        array $historico = [],
        bool $voz = false,
        ?callable $onToken = null,
        ?string $modelo = null,
    ): array {
        $conversa = self::resolverConversa($usuario, $modo, $conversaId, $pergunta);
        $anterior = $conversa->mensagens()
            ->orderBy('id')
            ->get()
            ->map(fn ($mensagem) => [
                'papel' => $mensagem->autor === 'bot' ? 'assistant' : 'user',
                'texto' => $mensagem->texto,
            ])
            ->all();

        $historicoModelo = $anterior !== []
            ? self::historicoParaModelo($anterior)
            : self::historicoParaModelo($historico);

        $resposta = SofiaAssistant::responder($pergunta, $historicoModelo, $onToken, $modelo);

        $conversa->mensagens()->create([
            'autor' => 'user',
            'texto' => $pergunta,
            'voz' => $voz,
        ]);
        $conversa->mensagens()->create([
            'autor' => 'bot',
            'texto' => $resposta,
            'voz' => false,
        ]);
        $conversa->update(['modo' => $modo]);

        return [
            'resposta' => $resposta,
            'conversa' => $conversa->fresh(['mensagens']) ?? $conversa,
        ];
    }

    public static function tituloDe(string $pergunta): string
    {
        $limpo = trim(preg_replace('/\s+/u', ' ', $pergunta) ?? $pergunta);
        if ($limpo === '') {
            return 'Novo chat';
        }

        return mb_strlen($limpo) > 48
            ? mb_substr($limpo, 0, 45).'…'
            : $limpo;
    }

    private static function resolverConversa(User $usuario, string $modo, ?string $conversaId, string $pergunta): SofiaConversa
    {
        if ($conversaId !== null && $conversaId !== '') {
            $existente = SofiaConversa::query()->find($conversaId);
            if ($existente !== null) {
                abort_unless($existente->user_id === $usuario->id, 404);

                return $existente;
            }

            return SofiaConversa::create([
                'id' => $conversaId,
                'user_id' => $usuario->id,
                'titulo' => self::tituloDe($pergunta),
                'modo' => $modo,
            ]);
        }

        return SofiaConversa::create([
            'id' => (string) Str::uuid(),
            'user_id' => $usuario->id,
            'titulo' => self::tituloDe($pergunta),
            'modo' => $modo,
        ]);
    }

    /**
     * @param  list<array{papel?: string, texto?: string}>  $historico
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private static function historicoParaModelo(array $historico): array
    {
        $turnos = [];

        foreach (array_slice($historico, -SofiaAssistant::MAX_HISTORY) as $item) {
            $papel = $item['papel'] ?? '';
            $texto = trim((string) ($item['texto'] ?? ''));

            if ($texto === '' || ($papel !== 'user' && $papel !== 'assistant')) {
                continue;
            }

            $turnos[] = ['role' => $papel, 'content' => $texto];
        }

        return $turnos;
    }
}
