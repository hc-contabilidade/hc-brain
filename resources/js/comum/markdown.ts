/* =========================================================
   Markdown seguro para respostas da Sofia: escapa HTML e só
   liga negrito, itálico e links http(s) ou caminhos internos.
   ========================================================= */

import { escapar } from "./formato.ts";

/** HTML a partir de um markdown simples (já escapado contra XSS). */
export function htmlDeMarkdown(texto: string): string {
    let html = escapar(texto);

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
        .replace(/\[([^\]]+)\]\(((?:\/[^)\s]+)|(?:https?:\/\/[^)\s]+))\)/g, "$1")
        .replace(/\*\*([^*]+)\*\*/g, "$1")
        .replace(/__([^_]+)__/g, "$1")
        .replace(/(^|[^*])\*([^*\n]+)\*([^*]|$)/g, "$1$2$3")
        .replace(/(^|[^_])_([^_\n]+)_([^_]|$)/g, "$1$2$3")
        .replace(/\s+/g, " ")
        .trim();
}
