/* =========================================================
   ESTADO COMPARTILHADO
   O que é escolha de quem está usando — termo de busca, filtros,
   pasta aberta, mês do calendário, conversa com a Sofia — não é
   dado da empresa, então continua no navegador: gravado no
   localStorage e replicado para as outras páginas (e outras abas)
   via evento "storage" e BroadcastChannel.

   Tudo o que é registro da HC (empresas, reuniões, usuários…) fica
   no banco, atrás da API.
   ========================================================= */

import {
    tituloDaConversa,
    type ConversaSofia,
    type MensagemSofia,
} from "../dados/sofia.ts";

const CHAVE = "hcBrainEstado";
const CANAL = "hcBrainSincronia";

export interface BuscaRecente {
    termo: string;
    contexto: string;
}

export type VisualizacaoDocumentos = "grid" | "list";
export type VisualizacaoLista = "cards" | "list";
export type VisualizacaoAgenda = VisualizacaoLista | "calendar";
export type EscalaCalendario = "dia" | "4dias" | "semana" | "mes" | "ano";
export type ModoSofia = "search" | "base";

export interface EstadoPesquisa {
    termo: string;
    escopo: string;
    tipo: string;
    area: string;
    periodo: string;
    recentes: BuscaRecente[];
}

export interface EstadoDocumentos {
    busca: string;
    tipo: string;
    pasta: string;
    visualizacao: VisualizacaoDocumentos;
}

export interface EstadoReunioes {
    busca: string;
    tipo: string;
    empresa: string;
    status: string;
    responsavel: string;
    /** Início do recorte, em AAAA-MM-DD. Vazio é "desde a primeira reunião". */
    de: string;
    /** Fim do recorte, em AAAA-MM-DD. Vazio é "até a última reunião". */
    ate: string;
    visualizacao: VisualizacaoAgenda;
    /** Quanto tempo o calendário mostra de uma vez: um dia, quatro, uma semana, um mês ou o ano. */
    escalaCalendario: EscalaCalendario;
    /**
     * Dia que ancora a janela do calendário, em AAAA-MM-DD; null é hoje. A
     * escala decide o que da data importa: a semana que a contém, o mês, o ano.
     */
    ancoraCalendario: string | null;
}

export interface EstadoEmpresas {
    busca: string;
    visualizacao: VisualizacaoLista;
}

export interface EstadoUsuarios {
    busca: string;
    perfil: string;
    status: string;
    /** Quantos usuários cabem na tabela por vez (10, 20 ou 50). */
    porPagina: number;
    /** Página atual da tabela (1-based), respeitando os filtros. */
    pagina: number;
}

export interface EstadoTreinamentos {
    busca: string;
    tipo: string;
    nivel: string;
}

export interface EstadoFinanceiro {
    busca: string;
    regime: string;
}

export interface EstadoComercial {
    periodo: string;
}

export interface EstadoSofia {
    modo: ModoSofia;
    /** null é um chat novo, ainda sem mensagem — não entra na lista. */
    conversaAtiva: string | null;
    conversas: ConversaSofia[];
}

export interface Estado {
    pesquisa: EstadoPesquisa;
    documentos: EstadoDocumentos;
    reunioes: EstadoReunioes;
    empresas: EstadoEmpresas;
    usuarios: EstadoUsuarios;
    treinamentos: EstadoTreinamentos;
    financeiro: EstadoFinanceiro;
    comercial: EstadoComercial;
    sofia: EstadoSofia;
}

export type SecaoEstado = keyof Estado;

export interface MudancaEstado {
    secoes: SecaoEstado[];
    origem: "local" | "externo";
    estado: Estado;
}

type Ouvinte = (mudanca: MudancaEstado) => void;

const ESTADO_PADRAO: Estado = {
    pesquisa: {
        termo: "",
        escopo: "Tudo",
        tipo: "Tudo",
        area: "Todas",
        periodo: "Qualquer período",
        // As buscas recentes são as de quem está usando: a lista nasce vazia
        // e cresce a cada pesquisa feita, aqui ou no Início.
        recentes: [],
    },
    documentos: {
        busca: "",
        tipo: "all",
        pasta: "Todas",
        visualizacao: "grid",
    },
    reunioes: {
        busca: "",
        tipo: "Todas",
        empresa: "Todas",
        status: "Todos",
        responsavel: "Todos",
        // Sem recorte de data: quem chega do detalhe de uma empresa precisa
        // ver o histórico inteiro dela, não só o mês corrente.
        de: "",
        ate: "",
        visualizacao: "list",
        escalaCalendario: "mes",
        ancoraCalendario: null,
    },
    empresas: { busca: "", visualizacao: "list" },
    usuarios: {
        busca: "",
        perfil: "Todos",
        status: "Todos",
        porPagina: 10,
        pagina: 1,
    },
    treinamentos: { busca: "", tipo: "Todos", nivel: "Todos" },
    financeiro: { busca: "", regime: "Todos" },
    // Em branco, a tela de Comercial abre no mês mais recente que a base tem.
    comercial: { periodo: "" },
    sofia: { modo: "search", conversaAtiva: null, conversas: [] },
};

function conversaUtil(valor: unknown): valor is ConversaSofia {
    if (!valor || typeof valor !== "object") return false;
    const conversa = valor as ConversaSofia;
    return (
        typeof conversa.id === "string" &&
        typeof conversa.titulo === "string" &&
        typeof conversa.atualizadoEm === "number" &&
        Array.isArray(conversa.mensagens)
    );
}

/**
 * A versão anterior guardava uma conversa só, em `mensagens`. Quem já
 * conversou com a Sofia não perde esse histórico: ele vira o primeiro chat.
 */
function normalizarSofia(
    bruto: (EstadoSofia & { mensagens?: MensagemSofia[] }) | undefined,
): EstadoSofia {
    const modo: ModoSofia = bruto?.modo === "base" ? "base" : "search";
    const conversas = Array.isArray(bruto?.conversas)
        ? bruto.conversas.filter(conversaUtil)
        : [];

    if (!conversas.length && Array.isArray(bruto?.mensagens) && bruto.mensagens.length) {
        const id = crypto.randomUUID();
        return {
            modo,
            conversaAtiva: id,
            conversas: [
                {
                    id,
                    titulo: tituloDaConversa(bruto.mensagens),
                    atualizadoEm: Date.now(),
                    mensagens: bruto.mensagens,
                },
            ],
        };
    }

    const conversaAtiva = conversas.some(
        (conversa) => conversa.id === bruto?.conversaAtiva,
    )
        ? (bruto?.conversaAtiva ?? null)
        : null;

    return { modo, conversaAtiva, conversas };
}

function clonar<T>(valor: T): T {
    return structuredClone(valor);
}

/**
 * Junta o que estava salvo com o padrão, chave por chave, para que um
 * estado gravado por uma versão anterior não derrube a tela.
 */
function mesclar(padrao: unknown, salvo: unknown): unknown {
    if (!salvo || typeof salvo !== "object" || Array.isArray(salvo)) {
        return salvo === undefined || salvo === null ? clonar(padrao) : salvo;
    }

    const base = padrao as Record<string, unknown>;
    const resultado = clonar(base);
    for (const [chave, valor] of Object.entries(salvo)) {
        resultado[chave] =
            chave in base && !Array.isArray(base[chave]) && base[chave] !== null
                ? mesclar(base[chave], valor)
                : valor;
    }
    return resultado;
}

function ler(): Estado {
    try {
        const bruto = localStorage.getItem(CHAVE);
        const lido = mesclar(ESTADO_PADRAO, bruto ? JSON.parse(bruto) : null) as Estado;
        lido.sofia = normalizarSofia(lido.sofia);
        return lido;
    } catch {
        return clonar(ESTADO_PADRAO);
    }
}

function gravar(valor: Estado): void {
    try {
        localStorage.setItem(CHAVE, JSON.stringify(valor));
    } catch {
        // Modo privado ou armazenamento cheio: a tela segue em memória.
    }
}

let estado = ler();

const ouvintes = new Set<Ouvinte>();
const canal = "BroadcastChannel" in window ? new BroadcastChannel(CANAL) : null;
const idAba = Math.random().toString(36).slice(2);

interface AvisoDeSincronia {
    idAba: string;
    secoes: SecaoEstado[];
}

function avisar(secoes: SecaoEstado[], origem: MudancaEstado["origem"]): void {
    for (const ouvinte of ouvintes) ouvinte({ secoes, origem, estado });
}

function receberDeFora(): void {
    const anterior = estado;
    estado = ler();
    const secoes = (Object.keys(ESTADO_PADRAO) as SecaoEstado[]).filter(
        (secao) => JSON.stringify(anterior[secao]) !== JSON.stringify(estado[secao]),
    );
    if (secoes.length) avisar(secoes, "externo");
}

window.addEventListener("storage", (evento) => {
    if (evento.key === CHAVE) receberDeFora();
});

canal?.addEventListener("message", (evento: MessageEvent<AvisoDeSincronia>) => {
    if (evento.data?.idAba !== idAba) receberDeFora();
});

/** Snapshot completo do estado (somente leitura). */
export function obterEstado(): Estado {
    return estado;
}

/** Trecho do estado de uma tela específica. */
export function obterSecao<S extends SecaoEstado>(secao: S): Estado[S] {
    return estado[secao];
}

/**
 * Atualiza uma seção do estado, persiste e avisa as outras páginas/abas.
 * Aceita um objeto parcial ou uma função que recebe a seção atual.
 */
export function atualizarSecao<S extends SecaoEstado>(
    secao: S,
    alteracao: Partial<Estado[S]> | ((atual: Estado[S]) => Partial<Estado[S]>),
): Estado[S] {
    const atual = estado[secao];
    const parcial = typeof alteracao === "function" ? alteracao(atual) : alteracao;
    const proximo = (
        atual && typeof atual === "object" && !Array.isArray(atual)
            ? { ...atual, ...parcial }
            : parcial
    ) as Estado[S];

    if (JSON.stringify(atual) === JSON.stringify(proximo)) return estado[secao];

    estado = { ...estado, [secao]: proximo };
    gravar(estado);
    canal?.postMessage({ idAba, secoes: [secao] } satisfies AvisoDeSincronia);
    avisar([secao], "local");
    return proximo;
}

/**
 * Observa mudanças no estado. Informe `secoes` para reagir apenas ao que
 * interessa àquela tela; o retorno cancela a assinatura.
 */
export function observarEstado(
    secoes: SecaoEstado | SecaoEstado[],
    callback: Ouvinte,
): () => void {
    const filtro = Array.isArray(secoes) ? secoes : [secoes];
    const ouvinte: Ouvinte = (evento) => {
        if (
            filtro.length === 0 ||
            filtro.some((secao) => evento.secoes.includes(secao))
        ) {
            callback(evento);
        }
    };
    ouvintes.add(ouvinte);
    return () => {
        ouvintes.delete(ouvinte);
    };
}
