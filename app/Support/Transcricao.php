<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TRANSCRIÇÃO DE ÁUDIO
 * A Sofia responde a partir de texto. Um arquivo de áudio — gravado num
 * navegador sem reconhecimento de voz, ou anexado — vira texto aqui, se o
 * ambiente tiver chave. Sem a chave, a tela explica o que falta.
 */
final class Transcricao
{
    public static function configurada(): bool
    {
        return filled(config('hc.sofia.chave'));
    }

    public static function deArquivo(UploadedFile $arquivo): string
    {
        $caminho = $arquivo->getRealPath();
        $conteudo = $caminho !== false ? file_get_contents($caminho) : false;

        if ($conteudo === false) {
            throw new RuntimeException('A transcrição do áudio falhou.');
        }

        $resposta = Http::withToken((string) config('hc.sofia.chave'))
            ->timeout(45)
            ->acceptJson()
            ->attach('file', $conteudo, $arquivo->getClientOriginalName())
            ->post((string) config('hc.sofia.transcricao.url'), [
                'model' => config('hc.sofia.transcricao.modelo'),
                'language' => 'pt',
            ]);

        if ($resposta->failed()) {
            throw new RuntimeException('A transcrição do áudio falhou.');
        }

        return trim((string) $resposta->json('text'));
    }
}
