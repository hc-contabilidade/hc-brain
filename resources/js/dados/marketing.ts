/* =========================================================
   MARKETING — ideias de vídeo + métricas de redes
   ========================================================= */

import { atualizar, enviar, obter, remover } from "../comum/api.ts";

export interface PeriodoMarketing {
    id: string;
    rotulo: string;
}

export interface PontoSerie {
    rotulo: string;
    views: number;
    ganho: number;
    audiencia: number;
}

export interface MetricaYoutube {
    views: number;
    likes: number;
    comentarios: number;
    compartilhamentos: number;
    inscritosNovos: number;
    inscritosTotal: number;
    variacaoViews: number;
    variacaoInscritos: number;
    inscritosOuAlcance: number;
}

export interface MetricaInstagram {
    views: number;
    likes: number;
    comentarios: number;
    compartilhamentos: number;
    alcance: number;
    seguidores: number;
    variacaoViews: number;
    variacaoAlcance: number;
    variacaoSeguidores: number;
    inscritosOuAlcance: number;
}

export interface ConteudoDestaque {
    titulo: string;
    periodo: string;
    publicadoEm: string | null;
    views: number;
    likes: number;
    comentarios: number;
    ganhoInscritos: number;
    ganhoSeguidores: number;
    destaque: boolean;
    url: string | null;
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
    canais: {
        youtube: string;
        instagram: string;
    };
    youtube: MetricaYoutube;
    instagram: MetricaInstagram;
    serieYoutube: PontoSerie[];
    serieInstagram: PontoSerie[];
    destaqueYoutube: ConteudoDestaque | null;
    destaqueInstagram: ConteudoDestaque | null;
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
