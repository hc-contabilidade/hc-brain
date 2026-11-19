/* =========================================================
   MARKETING — ideias de vídeo + métricas de redes
   ========================================================= */

import { atualizar, enviar, obter, remover } from "../comum/api.ts";

export interface PeriodoMarketing {
    id: string;
    rotulo: string;
}

export interface PontoSerie {
    periodo: string;
    rotulo: string;
    views: number;
    ganho: number;
    audiencia: number;
    saldo: number;
}

export interface MetricaYoutube {
    views: number;
    likes: number;
    comentarios: number;
    compartilhamentos: number;
    inscritosNovos: number;
    inscritosPerdidos: number;
    saldoInscritos: number;
    inscritosTotal: number;
    variacaoViews: number;
    variacaoInscritos: number;
    variacaoAudiencia: number;
    engajamento: number;
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

export type FormatoConteudo = "video" | "short" | "post";

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
    formato?: FormatoConteudo | string;
    url: string | null;
}

export interface VideoRanking {
    titulo: string;
    periodo: string;
    publicadoEm: string | null;
    views: number;
    likes: number;
    comentarios: number;
    destaque: boolean;
    formato?: FormatoConteudo | string;
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
    youtubeInsights: boolean;
    youtube: MetricaYoutube;
    instagram: MetricaInstagram;
    serieYoutube: PontoSerie[];
    serieInstagram: PontoSerie[];
    videosYoutube: VideoRanking[];
    videosInstagram: VideoRanking[];
    destaqueYoutube: ConteudoDestaque | null;
    destaqueInstagram: ConteudoDestaque | null;
    ideias: IdeiaVideo[];
}

export interface YoutubeAnalyticsStatus {
    configurada: boolean;
    conectada: boolean;
    conta: {
        id: number;
        email: string;
        nome: string | null;
        channelId: string | null;
        sincronizadaEm: string | null;
        erro: string | null;
    } | null;
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

export function carregarYoutubeAnalytics(): Promise<YoutubeAnalyticsStatus> {
    return obter<YoutubeAnalyticsStatus>("/marketing/youtube-analytics");
}

export function desconectarYoutubeAnalytics(): Promise<void> {
    return remover("/marketing/youtube-analytics");
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
