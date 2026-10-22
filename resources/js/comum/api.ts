/* =========================================================
   CONVERSA COM O BACK-END
   Toda tela lê do mesmo lugar: a API do Laravel, na mesma sessão
   do navegador. Estas funções cuidam do token CSRF, do formato
   das respostas e do que fazer quando a sessão cai.
   ========================================================= */

const BASE = "/api";

/** Erro devolvido pela API, com os erros de validação separados por campo. */
export class ErroDeApi extends Error {
    constructor(
        readonly status: number,
        mensagem: string,
        readonly campos: Record<string, string[]> = {},
    ) {
        super(mensagem);
        this.name = "ErroDeApi";
    }

    /** Primeira mensagem de um campo, para mostrar direto na tela. */
    primeiroErro(campo: string): string | null {
        return this.campos[campo]?.[0] ?? null;
    }
}

function tokenCsrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ""
    );
}

interface CorpoDeErro {
    message?: string;
    errors?: Record<string, string[]>;
}

async function pedir<T>(caminho: string, opcoes: RequestInit = {}): Promise<T> {
    const resposta = await fetch(`${BASE}${caminho}`, {
        ...opcoes,
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...opcoes.headers,
        },
    });

    // Sessão expirada ou token vencido: a pessoa precisa entrar de novo.
    if (resposta.status === 401 || resposta.status === 419) {
        window.location.href = "/login";
        throw new ErroDeApi(resposta.status, "Sua sessão expirou.");
    }

    const corpo: unknown = await resposta.json().catch(() => null);

    if (!resposta.ok) {
        const erro = (corpo ?? {}) as CorpoDeErro;
        throw new ErroDeApi(
            resposta.status,
            erro.message ?? "Não foi possível falar com o servidor.",
            erro.errors ?? {},
        );
    }

    return corpo as T;
}

export function obter<T>(caminho: string): Promise<T> {
    return pedir<T>(caminho);
}

function comCorpo<T>(metodo: string, caminho: string, corpo: unknown): Promise<T> {
    return pedir<T>(caminho, {
        method: metodo,
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": tokenCsrf(),
        },
        body: JSON.stringify(corpo),
    });
}

export function enviar<T>(caminho: string, corpo: unknown): Promise<T> {
    return comCorpo<T>("POST", caminho, corpo);
}

export function atualizar<T>(caminho: string, corpo: unknown): Promise<T> {
    return comCorpo<T>("PUT", caminho, corpo);
}

export async function remover(caminho: string): Promise<void> {
    await pedir<null>(caminho, {
        method: "DELETE",
        headers: { "X-CSRF-TOKEN": tokenCsrf() },
    });
}

/**
 * Envio de arquivo vai como FormData: o navegador monta o `multipart` e
 * escolhe o boundary, então aqui não se define Content-Type na mão.
 */
export function enviarArquivo<T>(caminho: string, dados: FormData): Promise<T> {
    return pedir<T>(caminho, {
        method: "POST",
        headers: { "X-CSRF-TOKEN": tokenCsrf() },
        body: dados,
    });
}

/**
 * POST que lê um fluxo SSE. Cada `data:` vira um objeto; 4xx/5xx em JSON
 * seguem o mesmo ErroDeApi das outras chamadas.
 */
export async function enviarFluxo(
    caminho: string,
    corpo: unknown,
    aoEvento: (evento: Record<string, unknown>) => void,
): Promise<void> {
    const resposta = await fetch(`${BASE}${caminho}`, {
        method: "POST",
        headers: {
            Accept: "text/event-stream",
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": tokenCsrf(),
        },
        body: JSON.stringify(corpo),
    });

    if (resposta.status === 401 || resposta.status === 419) {
        window.location.href = "/login";
        throw new ErroDeApi(resposta.status, "Sua sessão expirou.");
    }

    const tipo = resposta.headers.get("content-type") ?? "";
    if (!tipo.includes("text/event-stream")) {
        const json: unknown = await resposta.json().catch(() => null);
        const erro = (json ?? {}) as CorpoDeErro;
        const validacao = Object.values(erro.errors ?? {}).flat()[0];
        throw new ErroDeApi(
            resposta.status || 502,
            erro.message
                ?? (typeof validacao === "string" ? validacao : null)
                ?? (resposta.status
                    ? `Não foi possível falar com o servidor (${resposta.status}).`
                    : "Não foi possível falar com o servidor."),
            erro.errors ?? {},
        );
    }

    if (!resposta.body) {
        throw new ErroDeApi(resposta.status, "Não consegui ler a resposta da Sofia.");
    }

    const leitor = resposta.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    while (true) {
        const lido = await leitor.read();
        if (lido.done) break;
        buffer += decoder.decode(lido.value, { stream: true });
        const blocos = buffer.split("\n\n");
        buffer = blocos.pop() ?? "";
        for (const bloco of blocos) {
            const linha = bloco.split("\n").find((item) => item.startsWith("data:"));
            if (!linha) continue;
            const bruto = linha.slice(5).trim();
            if (!bruto) continue;
            aoEvento(JSON.parse(bruto) as Record<string, unknown>);
        }
    }
}

/** Coleções vêm embrulhadas em `data` quando saem de um API Resource. */
export async function obterColecao<T>(caminho: string): Promise<T[]> {
    const resposta = await obter<{ data: T[] }>(caminho);
    return resposta.data;
}
