/* =========================================================
   REUNIÕES — histórico central, vinculado à empresa e ao sócio
   ========================================================= */

import { obter } from "../comum/api.ts";
import type { CorTag } from "./empresas.ts";

export type TipoReuniao =
    | "Abertura"
    | "Transferência"
    | "Dúvidas"
    | "Comercial"
    | "Alinhamento"
    | "Financeira"
    | "Interna"
    | "Outra";

export type StatusReuniao = "Concluída" | "Agendada" | "Cancelada";

export interface Reuniao {
    id: number;
    /** "Sem empresa" quando veio do Google Agenda sem pista de quem é. */
    empresa: string;
    empresaId: string | null;
    /** Título do evento no Google Agenda, quando a reunião veio de lá. */
    titulo: string | null;
    /** Sócio responsável da empresa, mostrado no modal da reunião. */
    socio: string | null;
    tipo: TipoReuniao;
    data: string;
    dataOrd: string;
    /** O horário ainda não é registrado na base; a agenda mostra "—" sem ele. */
    horario: string | null;
    responsavel: string;
    participantes: string[];
    resumo: string;
    decisoes: string[];
    proximosPassos: string[];
    status: StatusReuniao;
    /** Só as reuniões trazidas do Google Agenda têm link do Meet. */
    linkMeet: string | null;
    /** A cor do evento no Google Agenda (#rrggbb); sem ela, vale a do status. */
    cor: string | null;
    /** Conta Google de onde a reunião veio; null se foi cadastrada à mão. */
    agenda: AgendaDaReuniao | null;
}

/** Conta Google conectada que originou a reunião. */
export interface AgendaDaReuniao {
    nome: string;
    email: string;
}

/** A empresa como o filtro e o cadastro precisam dela: nome para ler, id para gravar. */
export interface EmpresaDaReuniao {
    id: string;
    nome: string;
}

export interface BaseReunioes {
    reunioes: Reuniao[];
    empresas: EmpresaDaReuniao[];
}

export const tagDoTipoDeReuniao: Record<TipoReuniao, CorTag> = {
    Abertura: "green",
    Transferência: "blue",
    Dúvidas: "yellow",
    Comercial: "purple",
    Alinhamento: "gray",
    Financeira: "red",
    // Amarelo como o Banana que marca a reunião interna no Google Agenda.
    Interna: "yellow",
    Outra: "gray",
};

export const tagDoStatus: Record<StatusReuniao, CorTag> = {
    Concluída: "green",
    Agendada: "blue",
    Cancelada: "red",
};

/** Ordem das fatias no gráfico de movimento por dia. */
export const STATUS_DE_REUNIAO: StatusReuniao[] = ["Agendada", "Concluída", "Cancelada"];

export function carregarReunioes(): Promise<BaseReunioes> {
    return obter<BaseReunioes>("/reunioes");
}
