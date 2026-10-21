<?php

namespace App\Support;

/**
 * RESPOSTAS DA SOFIA
 * A Sofia lê a mesma base das outras telas por ferramentas Eloquent e
 * redige a resposta com Chat Completions. A tela só envia a pergunta
 * (e o histórico da conversa) e mostra o texto que volta.
 */
final class Sofia
{
    public static function configurada(): bool
    {
        return SofiaAssistant::configurada();
    }

    /**
     * @param  list<array{papel?: string, texto?: string}>  $historico
     */
    public static function responder(string $pergunta, array $historico = []): string
    {
        return SofiaAssistant::responder($pergunta, self::historicoParaModelo($historico));
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
