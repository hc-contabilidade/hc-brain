/* =========================================================
   FORMATAÇÃO
   Como número, dinheiro e nome viram texto na tela. Existe um
   lugar só para isso, então "R$ 8.700" tem a mesma cara no
   Financeiro, no Comercial e no detalhe do cliente.
   ========================================================= */

const ESCAPES: Record<string, string> = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
};

const MOEDA = new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: "BRL",
    maximumFractionDigits: 0,
});

const NUMERO = new Intl.NumberFormat("pt-BR");

/** Até duas letras do nome, para os avatares. */
export function iniciais(nome: string): string {
    return nome
        .split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0])
        .join("")
        .toUpperCase();
}

export function moeda(valor: number | null | undefined): string {
    return MOEDA.format(Number(valor ?? 0));
}

export function numero(valor: number | null | undefined): string {
    return NUMERO.format(Number(valor ?? 0));
}

/** CNPJ 00.000.000/0000-00 ou CPF 000.000.000-00. Outro tamanho fica só com os dígitos. */
export function documento(digitos: string): string {
    const numeros = digitos.replace(/\D/g, "");

    if (numeros.length === 14) {
        return numeros.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, "$1.$2.$3/$4-$5");
    }

    if (numeros.length === 11) {
        return numeros.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, "$1.$2.$3-$4");
    }

    return numeros || digitos;
}

export function percentual(valor: number, casas = 1): string {
    return `${valor.toFixed(casas).replace(".", ",")}%`;
}

/**
 * Quem conta os meses de relacionamento é o servidor, que conhece a data de
 * referência do sistema; aqui só transformamos o número em texto.
 */
export function duracaoEmMeses(meses: number): string {
    const anos = Math.floor(meses / 12);
    const resto = meses % 12;
    if (anos && resto) return `${anos}a ${resto}m`;
    if (anos) return `${anos} ano${anos > 1 ? "s" : ""}`;
    return `${meses} ${meses === 1 ? "mês" : "meses"}`;
}

export function plural(
    quantidade: number,
    singular: string,
    pluralForma: string,
): string {
    return `${numero(quantidade)} ${quantidade === 1 ? singular : pluralForma}`;
}

/** Mês por extenso com só a primeira letra maiúscula: "Setembro de 2026". */
export function mesPorExtenso(data: Date): string {
    const mes = data.toLocaleDateString("pt-BR", { month: "long" });
    return `${mes[0].toUpperCase()}${mes.slice(1)} de ${data.getFullYear()}`;
}

/** "2026-12-01" vira "01/12/2026", sem passar por `Date` e seu fuso. */
export function dataCurta(iso: string): string {
    const [ano, mes, dia] = iso.split("-");
    return dia ? `${dia}/${mes}/${ano}` : iso;
}

/** Escapa texto antes de injetar em template de HTML. */
export function escapar(texto: unknown): string {
    return String(texto ?? "").replace(/[&<>"']/g, (char) => ESCAPES[char] ?? char);
}
