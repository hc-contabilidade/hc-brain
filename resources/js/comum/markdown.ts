/* =========================================================
   Markdown seguro para respostas da Sofia: escapa HTML e só
   liga negrito, itálico e links http(s) ou caminhos internos.
   ========================================================= */

import { escapar } from "./formato.ts";

/**
 * Junta linhas consecutivas de campo (**Tipo:** …) numa só, separadas por ·.
 * Evita o bloco vertical bagunçado no dock estreito — mesmo visual da tela cheia.
 */
export function compactarCamposMarkdown(texto: string): string {
    return texto.replace(/\n(?=\*\*[^*]+\*\*)/g, (quebra, offset, fonte) => {
        const atual = fonte.slice(0, offset).split("\n").at(-1) ?? "";
        const proxima = fonte.slice(offset + 1).split("\n")[0] ?? "";
        const ehCampo = (linha: string): boolean => /^\*\*[^*]+:\*\*/.test(linha.trim());

        return ehCampo(atual) && ehCampo(proxima) ? " · " : quebra;
    });
}

/** HTML a partir de um markdown simples (já escapado contra XSS). */
export function htmlDeMarkdown(texto: string): string {
    let html = escapar(compactarCamposMarkdown(texto));

    // Imagens antes dos links: ![alt](url) não pode virar âncora.
    html = html.replace(
        /!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/g,
        '<img src="$2" alt="$1" class="sofia-md-img" loading="lazy" />',
    );
    html = html.replace(
        /!\[([^\]]*)\]\((\/[^)\s]+)\)/g,
        '<img src="$2" alt="$1" class="sofia-md-img" loading="lazy" />',
    );
    html = html.replace(
        /\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    );
    html = html.replace(/\[([^\]]+)\]\((\/[^)\s]+)\)/g, '<a href="$2">$1</a>');
    html = html.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
    html = html.replace(/__([^_]+)__/g, "<strong>$1</strong>");
    html = html.replace(/(^|[^*])\*([^*\n]+)\*([^*]|$)/g, "$1<em>$2</em>$3");
    html = html.replace(/(^|[^_])_([^_\n]+)_([^_]|$)/g, "$1<em>$2</em>$3");

    return html;
}

/** Texto limpo para voz alta sem asteriscos de markdown. */
export function textoSemMarkdown(texto: string): string {
    return texto
        .replace(/!\[([^\]]*)\]\(((?:\/[^)\s]+)|(?:https?:\/\/[^)\s]+))\)/g, "$1")
        .replace(/\[([^\]]+)\]\(((?:\/[^)\s]+)|(?:https?:\/\/[^)\s]+))\)/g, "$1")
        .replace(/\*\*([^*]+)\*\*/g, "$1")
        .replace(/__([^_]+)__/g, "$1")
        .replace(/(^|[^*])\*([^*\n]+)\*([^*]|$)/g, "$1$2$3")
        .replace(/(^|[^_])_([^_\n]+)_([^_]|$)/g, "$1$2$3")
        .replace(/\s+/g, " ")
        .trim();
}

/**
 * Texto preparado para a síntese: tira markdown, marcações de lista e
 * transforma quebras em pausas curtas (ponto), sem soar atropelado.
 */
export function textoParaFala(texto: string): string {
    return texto
        .replace(/!\[([^\]]*)\]\(((?:\/[^)\s]+)|(?:https?:\/\/[^)\s]+))\)/g, "$1")
        .replace(/\[([^\]]+)\]\(((?:\/[^)\s]+)|(?:https?:\/\/[^)\s]+))\)/g, "$1")
        .replace(/\*\*([^*]+)\*\*/g, "$1")
        .replace(/__([^_]+)__/g, "$1")
        .replace(/(^|[^*])\*([^*\n]+)\*([^*]|$)/g, "$1$2$3")
        .replace(/(^|[^_])_([^_\n]+)_([^_]|$)/g, "$1$2$3")
        .replace(/^\s{0,3}#{1,6}\s+/gm, "")
        .replace(/^\s*[-*•]\s+/gm, "")
        .replace(/\n{2,}/g, ". ")
        .replace(/\n/g, ". ")
        .replace(/\s+/g, " ")
        .replace(/\.\s*\./g, ".")
        .replace(/\s+([,.;:!?])/g, "$1")
        .trim();
}
