/* =========================================================
   EMPRESAS — entidade principal do banco
     → sócio responsável (dados próprios)
     → fontes vinculadas diretamente à empresa
     → reuniões vinculadas (aparecem também na tela de Reuniões)

   Os registros moraram em um array aqui; agora moram no banco e
   chegam pela API. O formato continua o mesmo, então as telas
   leem empresa, sócio e fonte exatamente como antes.
   ========================================================= */

import {
    atualizar,
    atualizarParcial,
    enviar,
    obter,
    obterColecao,
    remover,
} from "../comum/api.ts";
import type { Reuniao } from "./reunioes.ts";
import type { ConteudoTreinamento } from "./treinamentos.ts";

/** Cores das etiquetas compartilhadas por empresas e reuniões. */
export type CorTag = "green" | "blue" | "yellow" | "purple" | "gray" | "red";

export interface SocioEmpresa {
    nome: string;
    cargo: string;
    email: string;
    telefone: string;
    participacao: string;
    desde: string;
}

export interface FonteEmpresa {
    tipo: "PDF" | "DOC" | "XLS";
    nome: string;
    info: string;
}

export interface ResumoReuniao {
    tipo: string;
    data: string;
}

/** A empresa como a lista de clientes recebe: das fontes, só o total. */
export interface Empresa {
    id: string;
    nome: string;
    setor: string;
    status: string;
    statusTag: CorTag;
    /** App Omie + código do cliente; null se ainda não vinculado. */
    omieAppId: number | null;
    omieAppNome?: string | null;
    omieCodigoCliente: number | null;
    socio: SocioEmpresa;
    totalFontes: number;
    totalReunioes: number;
    ultimaReuniao: ResumoReuniao | null;
}

/** No detalhe do cliente a empresa chega com as fontes em si. */
export interface EmpresaDetalhada extends Empresa {
    fontes: FonteEmpresa[];
}

export interface ReceitaMes {
    mes: string;
    valor: number;
}

export interface TransacaoEmpresa {
    data: string;
    desc: string;
    cat: string;
    tipo: "Entrada" | "Saída";
    valor: number;
    status: string;
}

export const REGIMES_TRIBUTARIOS = [
    "Lucro Presumido",
    "MEI",
    "Simples Nacional",
    "Pessoa Física",
] as const;

export type RegimeTributario = (typeof REGIMES_TRIBUTARIOS)[number];

export interface FinanceiroEmpresa {
    regime: RegimeTributario;
    desde: string;
    /** Meses completos de relacionamento, contados pelo servidor. */
    meses: number;
    primeiro: number;
    atual: number;
    total: number;
    saldo: number;
    receber: number;
    despesas: number;
    margem: number;
    receitaMensal: ReceitaMes[];
    transacoes: TransacaoEmpresa[];
}

/** Tudo o que o detalhe do cliente mostra, em uma resposta só. */
export interface DetalheEmpresa {
    empresa: EmpresaDetalhada;
    reunioes: Reuniao[];
    treinamentos: ConteudoTreinamento[];
    /** Nem toda empresa tem relacionamento financeiro registrado. */
    financeiro: FinanceiroEmpresa | null;
}

/** Uma empresa da carteira, como o Financeiro geral precisa dela. */
export interface LinhaCarteira {
    id: string;
    nome: string;
    setor: string;
    socio: string;
    regime: RegimeTributario;
    desde: string;
    meses: number;
    primeiro: number;
    atual: number;
    total: number;
}

/**
 * A cor de cada regime nos gráficos. São tokens, não hex soltos: mudar a
 * paleta em base.css muda o gráfico junto.
 */
export const corDoRegime: Record<RegimeTributario, string> = {
    "Lucro Presumido": "var(--verde)",
    MEI: "var(--roxo-claro)",
    "Simples Nacional": "var(--azul)",
    "Pessoa Física": "var(--amarelo)",
};

/** Usada quando o regime não está no mapa acima. */
export const COR_PADRAO_DO_GRAFICO = "var(--texto-fraco)";

/** As cores de status que a API aceita, na ordem em que fazem sentido oferecer. */
export const TAGS_DE_STATUS: { valor: CorTag; rotulo: string }[] = [
    { valor: "green", rotulo: "Verde — tudo em dia" },
    { valor: "blue", rotulo: "Azul — em andamento" },
    { valor: "yellow", rotulo: "Amarelo — atenção" },
    { valor: "purple", rotulo: "Roxo — destaque" },
    { valor: "red", rotulo: "Vermelho — crítico" },
    { valor: "gray", rotulo: "Cinza — neutro" },
];

/** Empresa e sócio chegam juntos: uma empresa sem sócio não é registro completo. */
export interface DadosDeEmpresa {
    nome: string;
    setor: string;
    status: string;
    statusTag: string;
    socio: {
        nome: string;
        cargo: string;
        email: string;
        telefone: string;
        participacao: string;
        desde: string;
    };
}

export function carregarEmpresas(): Promise<Empresa[]> {
    return obterColecao<Empresa>("/empresas");
}

export function carregarEmpresa(id: string): Promise<DetalheEmpresa> {
    return obter<DetalheEmpresa>(`/empresas/${encodeURIComponent(id)}`);
}

export async function cadastrarEmpresa(dados: DadosDeEmpresa): Promise<Empresa> {
    const resposta = await enviar<{ data: Empresa }>("/empresas", dados);
    return resposta.data;
}

export async function salvarEmpresa(id: string, dados: DadosDeEmpresa): Promise<Empresa> {
    const resposta = await atualizar<{ data: Empresa }>(
        `/empresas/${encodeURIComponent(id)}`,
        dados,
    );
    return resposta.data;
}

export function excluirEmpresa(id: string): Promise<void> {
    return remover(`/empresas/${encodeURIComponent(id)}`);
}

export function carregarCarteira(): Promise<LinhaCarteira[]> {
    return obter<LinhaCarteira[]>("/financeiro");
}

/** Empresa da lista de baixo do financeiro, no formato da automação. */
export interface EmpresaDaLista {
    id: string;
    codigo: string | null;
    nome: string;
    documento: string | null;
    documentoTipo: "CNPJ" | "CPF" | null;
    status: string;
    statusTag: CorTag;
    regime: string | null;
    meses: number | null;
    primeiro: number | null;
    atual: number | null;
    total: number | null;
}

export function carregarEmpresasDaLista(): Promise<EmpresaDaLista[]> {
    return obter<EmpresaDaLista[]>("/financeiro/empresas");
}

export async function salvarVinculoOmie(
    id: string,
    omieAppId: number | null,
    omieCodigoCliente: number | null,
): Promise<Empresa> {
    const resposta = await atualizarParcial<{ data: Empresa }>(
        `/empresas/${encodeURIComponent(id)}/omie`,
        { omieAppId, omieCodigoCliente },
    );
    return resposta.data;
}

export interface OmieAppResumo {
    id: number;
    nome: string;
    appKey: string;
    ativo: boolean;
    temSecret: boolean;
}

export function carregarOmieApps(): Promise<{
    podeGerenciar: boolean;
    apps: OmieAppResumo[];
}> {
    return obter("/omie-apps");
}

export async function salvarOmieApp(dados: {
    nome: string;
    appKey: string;
    appSecret?: string;
    ativo?: boolean;
    id?: number;
}): Promise<OmieAppResumo> {
    if (dados.id) {
        const resposta = await atualizar<{ data: OmieAppResumo }>(`/omie-apps/${dados.id}`, {
            nome: dados.nome,
            appKey: dados.appKey,
            appSecret: dados.appSecret || undefined,
            ativo: dados.ativo ?? true,
        });
        return resposta.data;
    }

    const resposta = await enviar<{ data: OmieAppResumo }>("/omie-apps", {
        nome: dados.nome,
        appKey: dados.appKey,
        appSecret: dados.appSecret,
        ativo: dados.ativo ?? true,
    });
    return resposta.data;
}

export function excluirOmieApp(id: number): Promise<void> {
    return remover(`/omie-apps/${id}`);
}

export function sincronizarOmieEmpresa(id: string): Promise<DetalheEmpresa> {
    return enviar<DetalheEmpresa>(
        `/empresas/${encodeURIComponent(id)}/omie/sincronizar`,
        {},
    );
}
