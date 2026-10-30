<?php

namespace App\Support;

/**
 * Monta um PDF simples a partir de texto puro, sem biblioteca externa.
 * Serve para listas e relatórios curtos que a Sofia grava em Documentos.
 */
final class TextoPdf
{
    private const LARGURA = 595.28;

    private const ALTURA = 841.89;

    private const MARGEM = 50.0;

    private const TAMANHO_FONTE = 11.0;

    private const ALTURA_LINHA = 14.0;

    private const TAMANHO_TITULO = 16.0;

    private const ID_CATALOG = 1;

    private const ID_PAGES = 2;

    private const ID_FONT = 3;

    public static function de(string $titulo, string $conteudo): string
    {
        $tituloLatin = self::paraLatin1($titulo);
        $linhas = self::quebrarLinhas(self::paraLatin1($conteudo));
        $paginas = self::paginar($tituloLatin, $linhas);

        $objetos = [
            self::ID_CATALOG => '<< /Type /Catalog /Pages '.self::ID_PAGES.' 0 R >>',
            self::ID_FONT => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $kids = [];
        $proximoId = self::ID_FONT + 1;

        foreach ($paginas as $conteudoPagina) {
            $idConteudo = $proximoId++;
            $idPagina = $proximoId++;
            $objetos[$idConteudo] = '<< /Length '.strlen($conteudoPagina)." >>\nstream\n{$conteudoPagina}\nendstream";
            $objetos[$idPagina] = sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
                self::ID_PAGES,
                self::LARGURA,
                self::ALTURA,
                $idConteudo,
                self::ID_FONT,
            );
            $kids[] = "{$idPagina} 0 R";
        }

        $objetos[self::ID_PAGES] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

        return self::serializar($objetos);
    }

    /**
     * @param  list<string>  $linhas
     * @return list<string>
     */
    private static function paginar(string $titulo, array $linhas): array
    {
        $util = self::ALTURA - (2 * self::MARGEM);
        $linhasPorPagina = (int) floor(($util - self::TAMANHO_TITULO - 20) / self::ALTURA_LINHA);
        $linhasPorPagina = max(1, $linhasPorPagina);

        if ($linhas === []) {
            $linhas = [''];
        }

        $paginas = [];
        $primeiro = true;
        foreach (array_chunk($linhas, $linhasPorPagina) as $bloco) {
            $cabecalho = $primeiro ? $titulo : $titulo.' (cont.)';
            $primeiro = false;
            $paginas[] = self::desenharPagina($cabecalho, $bloco);
        }

        return $paginas;
    }

    /**
     * @param  list<string>  $linhas
     */
    private static function desenharPagina(string $titulo, array $linhas): string
    {
        $y = self::ALTURA - self::MARGEM - self::TAMANHO_TITULO;
        $partes = [
            'BT',
            '/F1 '.self::TAMANHO_TITULO.' Tf',
            sprintf('1 0 0 1 %.2F %.2F Tm', self::MARGEM, $y),
            '('.self::escapar($titulo).') Tj',
        ];

        $y -= self::TAMANHO_TITULO + 16;
        $partes[] = '/F1 '.self::TAMANHO_FONTE.' Tf';
        $partes[] = sprintf('1 0 0 1 %.2F %.2F Tm', self::MARGEM, $y);
        $partes[] = sprintf('%.2F TL', self::ALTURA_LINHA);

        $primeira = true;
        foreach ($linhas as $linha) {
            $escapada = self::escapar($linha);
            $partes[] = $primeira
                ? "({$escapada}) Tj"
                : "T* ({$escapada}) Tj";
            $primeira = false;
        }

        $partes[] = 'ET';

        return implode("\n", $partes);
    }

    /**
     * @return list<string>
     */
    private static function quebrarLinhas(string $texto): array
    {
        $larguraUtil = self::LARGURA - (2 * self::MARGEM);
        $maxChars = max(20, (int) floor($larguraUtil / (self::TAMANHO_FONTE * 0.5)));
        $resultado = [];

        foreach (preg_split("/\r\n|\n|\r/", $texto) ?: [''] as $paragrafo) {
            if ($paragrafo === '') {
                $resultado[] = '';

                continue;
            }

            while (strlen($paragrafo) > $maxChars) {
                $corte = strrpos(substr($paragrafo, 0, $maxChars + 1), ' ');
                if ($corte === false || $corte < (int) ($maxChars * 0.4)) {
                    $corte = $maxChars;
                }
                $resultado[] = rtrim(substr($paragrafo, 0, $corte));
                $paragrafo = ltrim(substr($paragrafo, $corte));
            }

            $resultado[] = $paragrafo;
        }

        return $resultado;
    }

    private static function paraLatin1(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);

        return is_string($convertido) ? $convertido : $texto;
    }

    private static function escapar(string $texto): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
    }

    /**
     * @param  array<int, string>  $objetos
     */
    private static function serializar(array $objetos): string
    {
        ksort($objetos);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objetos as $id => $corpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$corpo}\nendobj\n";
        }

        $xref = strlen($pdf);
        $total = max(array_keys($objetos)) + 1;
        $pdf .= "xref\n0 {$total}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n<< /Size {$total} /Root ".self::ID_CATALOG." 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
