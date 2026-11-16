/* =========================================================
   MARKETING — ideias de vídeo + métricas de redes
   ========================================================= */

import { atualizar, enviar, obter, remover } from "../comum/api.ts";

export interface PeriodoMarketing {
    id: string;
    rotulo: string;
}

export type SerieMensal = [rotulo: string, valor: number];

export interface MetricaRede {
    views: number;
    likes: number;
    comentarios: number;
    compartilhamentos: number;
    inscritosOuAlcance: number;
}

export interface IdeiaVideo {
    id: number;
    titulo: string;
    descricao: string | null;
    plataforma: "youtube" | "instagram" | "ambos";
    status: "rascunho" | "aprovada" | "publicada" | "arquivada";
    responsavel: string | null;
    prioridade: "alta" | "media" | "baixa";
}

export interface ResumoMarketing {
    periodo: string;
    youtube: MetricaRede;
    instagram: MetricaRede;
    serieYoutube: SerieMensal[];
    serieInstagram: SerieMensal[];
    ideias: IdeiaVideo[];
}

export type PayloadIdeia = {
    titulo: string;
    descricao?: string | null;
    plataforma: IdeiaVideo["plataforma"];
    status: IdeiaVideo["status"];
    responsavel?: string | null;
    prioridade: IdeiaVideo["prioridade"];
};

export function carregarPeriodosMarketing(): Promise<PeriodoMarketing[]> {
    return obter<PeriodoMarketing[]>("/marketing/periodos");
}

export function carregarMarketing(periodo: string): Promise<ResumoMarketing> {
    return obter<ResumoMarketing>(
        `/marketing/${encodeURIComponent(periodo || "atual")}`,
    );
}

export function criarIdeia(dados: PayloadIdeia): Promise<IdeiaVideo> {
    return enviar<IdeiaVideo>("/marketing/ideias", dados);
}

export function atualizarIdeia(id: number, dados: PayloadIdeia): Promise<IdeiaVideo> {
    return atualizar<IdeiaVideo>(`/marketing/ideias/${id}`, dados);
}

export function excluirIdeia(id: number): Promise<void> {
    return remover(`/marketing/ideias/${id}`);
}
