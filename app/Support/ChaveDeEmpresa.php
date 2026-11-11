<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Junta a mesma empresa quando o financeiro e a planilha não escrevem o nome
 * igual: sem acento, sem LTDA, sem o número que às vezes vem na frente.
 */
final class ChaveDeEmpresa
{
    public static function nome(string $nome): string
    {
        $semCodigoNoFim = preg_replace('/\s*\(\d+\)\s*$/u', '', $nome) ?? $nome;
        $chave = Str::of($semCodigoNoFim)->ascii()->upper()->toString();
        $chave = str_replace('&', ' ', $chave);
        $chave = preg_replace('/[^A-Z0-9 ]+/', ' ', $chave) ?? $chave;
        $chave = preg_replace('/\s+/', ' ', trim($chave)) ?? $chave;
        $chave = preg_replace('/^(?:\d+\s+)+/', '', $chave) ?? $chave;

        do {
            $anterior = $chave;
            $chave = preg_replace('/\b(LTDA|EIRELI|EPP|MEI|ME|SA)\b/', ' ', $chave) ?? $chave;
            $chave = preg_replace('/\s+/', ' ', trim($chave)) ?? $chave;
        } while ($chave !== $anterior);

        return $chave;
    }
}
