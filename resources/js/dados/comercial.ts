/* =========================================================
   COMERCIAL — indicadores gerenciais por período
   (espelho do Painel de Vendas do Automacao)
   ========================================================= */

import { obter } from "../comum/api.ts";

/** Um mês fechado na base, como o filtro da tela precisa dele. */
export interface PeriodoComercial {
    id: string;
    rotulo: string;
}

export type MesLeads = [rotulo: string, leads: number];
export type OrigemLead = [nome: string, leads: number, cor: string];
export type MotivoPerda = [motivo: string, ocorrencias: number];
export type VendaFechada = [empresa: string, responsavel: string, tipo: string];
export type LinhaEquipe = [
    pessoa: string,
    leads: number,
    vendas: number,
    conversao: number,
    receita: number,
];
export type EtapaFunil = [nome: string, quantidade: number];

export interface IndicadoresComerciais {
    periodo: string;
    leads: number;
    /** Nulo no mês mais antigo da base: não há com o que comparar. */
    leadsAnteriores: number | null;
    qualified: number;
    meetings: number;
    meetingsDone: number;
    proposals: number;
    closed: number;
    abertura: number;
    transferencia: number;
    rebotes: number;
    revenue: number;
    averageTicket: number;
    inProcess: number;
    monthly: MesLeads[];
    origins: OrigemLead[];
    sources: OrigemLead[];
    funnel: EtapaFunil[];
    losses: MotivoPerda[];
    sales: VendaFechada[];
    team: LinhaEquipe[];
}

export function carregarPeriodosComerciais(): Promise<PeriodoComercial[]> {
    return obter<PeriodoComercial[]>("/comercial");
}

export function carregarComercial(periodo: string): Promise<IndicadoresComerciais> {
    return obter<IndicadoresComerciais>(
        `/comercial/${encodeURIComponent(periodo || "atual")}`,
    );
}
