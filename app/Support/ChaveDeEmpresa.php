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

    /**
     * Os oito primeiros dígitos do CNPJ quando o nome começa com eles,
     * no formato 00.000.000. Não é o código interno da empresa.
     */
    public static function raiz(string $nome): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{3})\.(\d{3})(?!\d)/u', trim($nome), $encontrado) !== 1) {
            return null;
        }

        return $encontrado[1].$encontrado[2].$encontrado[3];
    }

    /**
     * Agrupa a planilha pela primeira palavra para achar o nome fantasia
     * dentro da razão social sem comparar a carteira inteira com ela mesma.
     *
     * @param  array{porNome?: array<string, array<string, mixed>>}  $planilha
     * @return array<string, list<array{0: string, 1: array<string, mixed>}>>
     */
    public static function indice(array $planilha): array
    {
        $porPrimeira = [];

        foreach ($planilha['porNome'] ?? [] as $chave => $item) {
            if (! is_string($chave) || ! is_array($item)) {
                continue;
            }

            $primeira = strstr($chave, ' ', true);
            $porPrimeira[$primeira === false ? $chave : $primeira][] = [$chave, $item];
        }

        return $porPrimeira;
    }

    /**
     * A linha da planilha desta empresa: pelo código, pelo nome igual, pelo
     * nome fantasia que cabe no começo da razão social, ou pela raiz do CNPJ
     * que vem na frente do nome.
     *
     * @param  array{porCodigo?: array<string, array<string, mixed>>, porNome?: array<string, array<string, mixed>>, porRaiz?: array<string, array<string, mixed>>}  $planilha
     * @param  array<string, list<array{0: string, 1: array<string, mixed>}>>  $indice
     * @return array<string, mixed>|null
     */
    public static function linha(array $planilha, string $nome, ?string $codigo, array $indice): ?array
    {
        if ($codigo !== null && $codigo !== '' && is_array($planilha['porCodigo'][$codigo] ?? null)) {
            return $planilha['porCodigo'][$codigo];
        }

        $chave = self::nome($nome);

        if ($chave !== '' && is_array($planilha['porNome'][$chave] ?? null)) {
            return $planilha['porNome'][$chave];
        }

        $peloPrefixo = self::peloPrefixo($chave, $indice);

        if ($peloPrefixo !== null) {
            return $peloPrefixo;
        }

        $raiz = self::raiz($nome);

        if ($raiz !== null && is_array($planilha['porRaiz'][$raiz] ?? null)) {
            return $planilha['porRaiz'][$raiz];
        }

        return null;
    }

    /**
     * @param  array<string, list<array{0: string, 1: array<string, mixed>}>>  $indice
     * @return array<string, mixed>|null
     */
    private static function peloPrefixo(string $chave, array $indice): ?array
    {
        if (strlen($chave) < 8) {
            return null;
        }

        $primeira = strstr($chave, ' ', true);
        $candidatos = $indice[$primeira === false ? $chave : $primeira] ?? [];
        $achado = null;

        foreach ($candidatos as [$outra, $item]) {
            if ($outra === $chave || ! self::umaCabeNaOutra($chave, $outra)) {
                continue;
            }

            if ($achado !== null) {
                return null;
            }

            $achado = $item;
        }

        return $achado;
    }

    private static function umaCabeNaOutra(string $uma, string $outra): bool
    {
        $curta = strlen($uma) <= strlen($outra) ? $uma : $outra;
        $longa = $curta === $uma ? $outra : $uma;

        return str_starts_with($longa, $curta.' ');
    }
}
