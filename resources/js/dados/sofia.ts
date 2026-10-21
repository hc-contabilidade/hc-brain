/* =========================================================
   SOFIA — sugestões iniciais e respostas do assistente
   A tela manda a pergunta e o histórico; o servidor consulta a
   base pelas ferramentas da Sofia e devolve o texto.
   ========================================================= */

import { enviar, enviarArquivo, obter } from "../comum/api.ts";

export type AutorMensagem = "user" | "bot";

export interface MensagemSofia {
    autor: AutorMensagem;
    texto: string;
    /** Pergunta ditada ou transcrita a partir de um áudio. */
    voz?: boolean;
}

/** Uma conversa guardada no navegador, como um chat da lista lateral. */
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

export async function perguntarSofia(
    pergunta: string,
    historico: TurnoSofia[] = [],
): Promise<string> {
    const resposta = await enviar<{ resposta: string }>("/sofia/perguntar", {
        pergunta,
        historico,
    });
    return resposta.resposta;
}

/** Áudio anexado ou gravado sem reconhecimento no navegador. */
export function enviarAudioSofia(
    arquivo: Blob,
    nome: string,
    historico: TurnoSofia[] = [],
): Promise<{ transcricao: string; resposta: string }> {
    const dados = new FormData();
    const envio =
        arquivo instanceof File
            ? arquivo
            : new File([arquivo], nome, { type: arquivo.type || "audio/webm" });
    dados.append("audio", envio, nome);
    historico.forEach((turno, indice) => {
        dados.append(`historico[${indice}][papel]`, turno.papel);
        dados.append(`historico[${indice}][texto]`, turno.texto);
    });

    return enviarArquivo("/sofia/audio", dados);
}
