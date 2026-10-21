/* =========================================================
   AGENDAS DO GOOGLE — contas cujos eventos com Meet viram reunião
   Conectar sai do HC Brain para a tela de consentimento do Google,
   por isso é um link no Blade e não uma chamada da API.
   ========================================================= */

import { enviar, obter, remover } from "../comum/api.ts";

export interface ContaGoogle {
    id: number;
    email: string;
    nome: string;
    /** Data e hora da última leitura bem-sucedida, no horário de Brasília. */
    sincronizadaEm: string | null;
    /** O motivo da última leitura ter falhado; some na próxima que der certo. */
    erro: string | null;
}

export interface AgendasGoogle {
    /** Sem o cliente OAuth no ambiente não há como conectar nenhuma conta. */
    configurada: boolean;
    contas: ContaGoogle[];
}

export function carregarAgendas(): Promise<AgendasGoogle> {
    return obter<AgendasGoogle>("/agendas-google");
}

export interface LeituraDasAgendas extends AgendasGoogle {
    /** Uma linha por conta: eventos lidos, quantos têm Meet e o que virou reunião. */
    relatorio: string[];
}

/** Relê as agendas inteiras na hora e devolve como ficaram e o que cada uma trouxe. */
export function sincronizarAgendas(): Promise<LeituraDasAgendas> {
    return enviar<LeituraDasAgendas>("/agendas-google/sincronizar", {});
}

export function desconectarAgenda(id: number): Promise<void> {
    return remover(`/agendas-google/${id}`);
}
