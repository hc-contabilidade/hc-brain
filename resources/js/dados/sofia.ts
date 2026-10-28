/* =========================================================
   SOFIA — respostas do assistente oficial do HC Brain
   A tela manda a pergunta, o modo e o chat; o servidor consulta a
   base pelas ferramentas da Sofia e devolve o texto em fluxo.
   ========================================================= */

import {
    enviarArquivo,
    enviarFluxo,
    obter,
    remover,
    enviar,
    ErroDeApi,
} from "../comum/api.ts";
import type { ModoSofia } from "../comum/estado.ts";

export type AutorMensagem = "user" | "bot";

export interface MensagemSofia {
    autor: AutorMensagem;
    texto: string;
    /** Pergunta ditada ou transcrita a partir de um áudio. */
    voz?: boolean;
}

/** Uma conversa da Sofia: no servidor, com cache no navegador. */
export interface ConversaSofia {
    id: string;
    titulo: string;
    atualizadoEm: number;
    mensagens: MensagemSofia[];
}

/** Título da lista: a primeira pergunta, cortada para caber na lateral. */
export function tituloDaConversa(mensagens: MensagemSofia[]): string {
    const texto = mensagens.find((mensagem) => mensagem.autor === "user")?.texto ?? "";
    const limpo = texto.replace(/\s+/g, " ").trim();
    if (!limpo) return "Novo chat";
    return limpo.length > 48 ? `${limpo.slice(0, 45).trimEnd()}…` : limpo;
}

export interface SugestaoSofia {
    icon: string;
    text: string;
}

export function carregarSugestoes(): Promise<SugestaoSofia[]> {
    return obter<SugestaoSofia[]>("/sofia/sugestoes");
}

export function carregarConversasSofia(): Promise<ConversaSofia[]> {
    return obter<ConversaSofia[]>("/sofia/conversas");
}

export function importarConversasSofia(
    conversas: ConversaSofia[],
    modo: ModoSofia,
): Promise<ConversaSofia[]> {
    return enviar<ConversaSofia[]>("/sofia/conversas/importar", { conversas, modo });
}

export function apagarConversaSofia(id: string): Promise<void> {
    return remover(`/sofia/conversas/${id}`);
}

/** Troca anterior enviada ao modelo. `bot` na tela vira `assistant`. */
export interface TurnoSofia {
    papel: "user" | "assistant";
    texto: string;
}

const MAX_HISTORICO = 20;

/** Últimas trocas da conversa, já no formato da API. */
export function historicoDaConversa(mensagens: MensagemSofia[]): TurnoSofia[] {
    return mensagens
        .filter((mensagem) => mensagem.texto.trim() !== "")
        .map((mensagem): TurnoSofia => ({
            papel: mensagem.autor === "bot" ? "assistant" : "user",
            texto: mensagem.texto,
        }))
        .slice(-MAX_HISTORICO);
}

export interface PedidoSofia {
    modo: ModoSofia;
    conversaId: string | null;
    /** Id do catálogo (ex.: gpt-4o-mini, gemini-2.0-flash). */
    modelo?: string;
    aoToken?: (token: string) => void;
}

export interface RespostaSofia {
    resposta: string;
    conversa: ConversaSofia;
}

export async function perguntarSofia(
    pergunta: string,
    historico: TurnoSofia[] = [],
    pedido: PedidoSofia,
): Promise<RespostaSofia> {
    const corpo = {
        pergunta,
        historico,
        modo: pedido.modo,
        conversa_id: pedido.conversaId,
        modelo: pedido.modelo || undefined,
    };

    try {
        return await perguntarEmFluxo(corpo, pedido);
    } catch (erro) {
        // Sem chave ou sessão caída: não adianta tentar de outro jeito.
        if (
            erro instanceof ErroDeApi
            && (erro.status === 401 || erro.status === 419 || erro.status === 503)
        ) {
            throw erro;
        }

        // No Render/Apache o SSE às vezes volta sem event-stream; a API JSON
        // responde igual, só sem tokens um a um.
        return enviar<RespostaSofia>("/sofia/perguntar", corpo);
    }
}

async function perguntarEmFluxo(
    corpo: {
        pergunta: string;
        historico: TurnoSofia[];
        modo: ModoSofia;
        conversa_id: string | null;
        modelo?: string;
    },
    pedido: PedidoSofia,
): Promise<RespostaSofia> {
    let resposta = "";
    let conversa: ConversaSofia | null = null;
    let erro: string | null = null;

    await enviarFluxo("/sofia/perguntar", corpo, (evento) => {
        if (typeof evento.erro === "string") {
            erro = evento.erro;
            return;
        }
        if (typeof evento.token === "string") {
            resposta += evento.token;
            pedido.aoToken?.(evento.token);
        }
        if (
            evento.done === true
            && evento.conversa
            && typeof evento.resposta === "string"
        ) {
            resposta = evento.resposta;
            conversa = evento.conversa as ConversaSofia;
        }
    });

    if (erro) {
        throw new ErroDeApi(502, erro);
    }

    if (!conversa) {
        throw new ErroDeApi(
            502,
            "Não consegui consultar a base agora. Tente novamente em instantes.",
        );
    }

    return { resposta, conversa };
}

/** Áudio anexado ou gravado sem reconhecimento no navegador. */
export function enviarAudioSofia(
    arquivo: Blob,
    nome: string,
    historico: TurnoSofia[] = [],
    pedido: { modo: ModoSofia; conversaId: string | null; modelo?: string },
): Promise<{ transcricao: string; resposta: string; conversa: ConversaSofia }> {
    const dados = new FormData();
    const envio =
        arquivo instanceof File
            ? arquivo
            : new File([arquivo], nome, { type: arquivo.type || "audio/webm" });
    dados.append("audio", envio, nome);
    dados.append("modo", pedido.modo);
    if (pedido.conversaId) dados.append("conversa_id", pedido.conversaId);
    if (pedido.modelo) dados.append("modelo", pedido.modelo);
    historico.forEach((turno, indice) => {
        dados.append(`historico[${indice}][papel]`, turno.papel);
        dados.append(`historico[${indice}][texto]`, turno.texto);
    });

    return enviarArquivo("/sofia/audio", dados);
}
