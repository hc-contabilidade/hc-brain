/* =========================================================
   TELA SOFIA (IA)
   Vários chats, como um assistente de verdade: a lista fica na
   lateral, um chat novo começa em branco e a conversa aberta
   continua no navegador — inclusive em outra aba.

   O microfone dita a pergunta. Se o navegador reconhece voz, o
   texto cai no campo quando o ditado para, dá para editar e
   retomar de onde ficou. Se não reconhece, o áudio vai para o
   servidor e volta transcrito. Quando não há nenhum dos dois
   caminhos, a tela nem oferece o áudio: o microfone fica
   desabilitado e explica o que fazer, em vez de falhar depois do
   clique.

   A conversa por voz é o ditado em ciclo: ouvir, perguntar, falar
   a resposta em voz alta e voltar a ouvir. Ela toma a tela inteira,
   com uma esfera que se move conforme o estado — não há o que ler
   nem onde clicar enquanto se fala. Ouvir a resposta usa
   só a síntese de fala, que existe em todo navegador moderno —
   por isso o botão "Ouvir" das respostas funciona mesmo onde o
   reconhecimento não existe.
   ========================================================= */

import { ErroDeApi } from "../comum/api.ts";
import {
    apagarConversaSofia,
    carregarConversasSofia,
    enviarAudioSofia,
    historicoDaConversa,
    importarConversasSofia,
    perguntarSofia,
    tituloDaConversa,
    type AutorMensagem,
    type ConversaSofia,
    type MensagemSofia,
} from "../dados/sofia.ts";
import { combina, termoDeBusca } from "../comum/busca.ts";
import {
    alvoMaisProximo,
    campo,
    dado,
    porId,
    talvez,
    todos,
} from "../comum/dom.ts";
import {
    atualizarSecao,
    observarEstado,
    obterSecao,
    type ModoSofia,
} from "../comum/estado.ts";
import { escapar } from "../comum/formato.ts";
import { desenharIcones, icone } from "../comum/icones.ts";
import { htmlDeMarkdown, textoSemMarkdown } from "../comum/markdown.ts";
import { showModal } from "../comum/modal.ts";
import { iniciarPagina } from "../comum/shell.ts";

/** O reconhecimento de voz não é padrão em todos os navegadores. */
interface AlternativaDeVoz {
    transcript: string;
}

interface ResultadoDeVoz {
    isFinal: boolean;
    readonly length: number;
    [indice: number]: AlternativaDeVoz;
}

interface EventoDeVoz {
    resultIndex: number;
    results: ArrayLike<ResultadoDeVoz>;
}

interface ErroDeVoz {
    error: string;
}

interface ReconhecimentoDeVoz {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    onresult: ((evento: EventoDeVoz) => void) | null;
    onerror: ((evento: ErroDeVoz) => void) | null;
    onend: (() => void) | null;
    start(): void;
    stop(): void;
    abort(): void;
}

type ConstrutorDeReconhecimento = new () => ReconhecimentoDeVoz;

type ModoGravacao = "fala" | "arquivo";

/** Os três momentos do ciclo da conversa por voz. */
type EstadoDeVoz = "ouvindo" | "pensando" | "falando";

const LIMITE_GRAVACAO_MS = 120_000;

/** Silêncios seguidos antes de sair sozinha da conversa por voz. */
const LIMITE_DE_SILENCIOS = 3;

const usuario = iniciarPagina("sofia-ia");

const tela = porId("sofiaTela");
const coluna = porId("sofiaColuna");
const lista = porId("sofiaConversas");
const titulo = porId("sofiaTituloConversa");
const chat = porId("chat");
const boasVindas = porId("sofiaWelcome");
const boasVindasTexto = porId("sofiaWelcomeTexto");
const entrada = porId<HTMLTextAreaElement>("question");
const envio = porId<HTMLButtonElement>("sofiaSend");
const micWrap = porId("sofiaMicWrap");
const micBtn = porId<HTMLButtonElement>("sofiaMicBtn");
const anexarBtn = porId<HTMLButtonElement>("sofiaAttachBtn");
const anexo = porId("sofiaAttachment");
const anexoNome = porId("sofiaAttachmentName");
const arquivos = campo("sofiaFileInput");
const modoPesquisa = porId("sofiaSearchMode");
const modoBase = porId("sofiaComputerMode");
const fundoHistorico = porId("sofiaHistoricoFundo");
const buscaWrap = porId("sofiaBuscaWrap");
const buscaChats = porId<HTMLInputElement>("sofiaBuscaChats");
const gravacao = porId("sofiaGravacao");
const gravacaoEstado = porId("sofiaGravacaoEstado");
const gravacaoTempo = porId("sofiaGravacaoTempo");
const gravacaoParcial = porId("sofiaGravacaoParcial");
const gravacaoParar = porId<HTMLButtonElement>("sofiaGravacaoParar");
const gravacaoEnviar = porId<HTMLButtonElement>("sofiaGravacaoEnviar");
const vozWrap = porId("sofiaVozWrap");
const vozBtn = porId<HTMLButtonElement>("sofiaVozBtn");
const vozTela = porId("sofiaVozTela");
const vozFechar = porId<HTMLButtonElement>("sofiaVozFechar");
const vozEstado = porId("sofiaVozEstado");
const vozParcial = porId("sofiaVozParcial");
const vozInterromper = porId<HTMLButtonElement>("sofiaVozInterromper");
const esfera = porId("sofiaEsfera");
const dicaVoz = porId("sofiaDicaVoz");
const aviso = porId("sofiaAviso");

/* Os dois caminhos do áudio, decididos antes do primeiro clique: ditar no
   próprio navegador, ou mandar o arquivo para o servidor transcrever. */
const transcricaoNoServidor = dado(tela, "transcricaoServidor") === "1";
const ditadoNoNavegador = reconhecimentoDisponivel() !== null;
const audioDisponivel = ditadoNoNavegador || transcricaoNoServidor;

/* A síntese de fala é independente do reconhecimento: o Firefox não ouve,
   mas fala — então "Ouvir" continua lá mesmo sem ditado. */
const sinteseDeVoz: SpeechSynthesis | null =
    typeof window.speechSynthesis === "undefined" ? null : window.speechSynthesis;

const SEM_VOZ =
    "Este navegador não reconhece voz. Abra a Sofia no Chrome ou no Edge para " +
    "falar a pergunta, ou escreva aqui mesmo.";

const SEM_CONVERSA_POR_VOZ =
    "A conversa por voz precisa do reconhecimento de fala do navegador. Abra a " +
    "Sofia no Chrome ou no Edge, ou escreva a pergunta.";

porId("sofiaUsuarioAvatar").textContent = usuario.iniciais;
porId("sofiaUsuarioNome").textContent = usuario.nome;
porId("sofiaUsuarioPerfil").textContent = usuario.perfil;

const primeiroNome = usuario.nome.trim().split(/\s+/)[0];
if (primeiroNome) {
    porId("sofiaSaudacao").textContent = `Olá, ${primeiroNome}. O que você quer saber?`;
}

/** Chats que ainda estão esperando a resposta da Sofia. */
const aguardando = new Set<string>();

/** Chat que está esperando a transcrição de um arquivo de áudio. */
let transcrevendoId: string | null = null;

/** Arquivo de áudio escolhido no anexo, pronto para enviar. */
let audioAnexado: File | null = null;

let modoGravacao: ModoGravacao | null = null;
let reconhecimentoAtivo: ReconhecimentoDeVoz | null = null;
let gravador: MediaRecorder | null = null;
let streamMic: MediaStream | null = null;
let pedacos: Blob[] = [];
let cancelouGravacao = false;
let paradaPedida = false;
/** Parada que só encerra a escuta: o texto vai para o campo, sem enviar. */
let pausaParaEditar = false;
let timerGravacao: number | null = null;
let inicioGravacao = 0;
let transcricaoFinal = "";
let transcricaoParcial = "";

let conversaPorVoz = false;
let reconhecimentoDaConversa: ReconhecimentoDeVoz | null = null;
let silenciosSeguidos = 0;

/* A esfera: o nível vem do microfone enquanto ela ouve, e de uma onda
   aproximada enquanto a Sofia fala. */
let contextoDeAudio: AudioContext | null = null;
let analisador: AnalyserNode | null = null;
let amostras: Uint8Array<ArrayBuffer> | null = null;
let streamDaEsfera: MediaStream | null = null;
let quadroDaEsfera: number | null = null;
let inicioDaFala = 0;

/** Voz em português escolhida para a Sofia, quando o sistema tem uma. */
let vozDaSofia: SpeechSynthesisVoice | null = null;

/** Encerra a espera da fala em curso, inclusive quando ela é interrompida. */
let encerrarFalaAtual: (() => void) | null = null;

/** Índice da mensagem que está sendo lida em voz alta. */
let mensagemFalando: number | null = null;

function novoId(): string {
    return crypto.randomUUID();
}

function conversaAberta(): ConversaSofia | null {
    const { conversaAtiva, conversas } = obterSecao("sofia");
    return conversas.find((conversa) => conversa.id === conversaAtiva) ?? null;
}

function fecharHistorico(): void {
    tela.classList.remove("historico-aberto");
    fundoHistorico.hidden = true;
}

function abrirHistorico(): void {
    tela.classList.add("historico-aberto");
    fundoHistorico.hidden = false;
}

function ajustarCampo(): void {
    entrada.style.height = "auto";
    entrada.style.height = `${Math.min(entrada.scrollHeight, 160)}px`;
}

function esperandoResposta(): boolean {
    const aberta = conversaAberta();
    return aberta !== null && aguardando.has(aberta.id);
}

function atualizarEnvio(): void {
    envio.disabled =
        esperandoResposta() ||
        modoGravacao !== null ||
        (entrada.value.trim() === "" && audioAnexado === null);
    micBtn.disabled = !audioDisponivel || esperandoResposta() || conversaPorVoz;
    /* Em conversa por voz o botão continua ativo mesmo enquanto a Sofia pensa:
       é por ele que se sai do modo. */
    vozBtn.disabled =
        !conversaPorVoz &&
        (!ditadoNoNavegador || esperandoResposta() || modoGravacao !== null);
}

function mostrarAviso(texto: string): void {
    aviso.textContent = texto;
    aviso.hidden = false;
}

function limparAviso(): void {
    aviso.textContent = "";
    aviso.hidden = true;
}

function limparAnexo(): void {
    audioAnexado = null;
    arquivos.value = "";
    anexo.classList.remove("show");
    anexoNome.textContent = "";
    atualizarEnvio();
}

/**
 * `getVoices()` costuma devolver uma lista vazia na primeira chamada, porque o
 * sistema ainda está carregando as vozes — o evento avisa quando elas chegam.
 */
function escolherVozPortuguesa(): void {
    if (!sinteseDeVoz) return;
    const vozes = sinteseDeVoz.getVoices();
    vozDaSofia =
        vozes.find((voz) => /^pt[-_]br$/i.test(voz.lang)) ??
        vozes.find((voz) => /^pt/i.test(voz.lang)) ??
        null;
}

/** Fala o texto e resolve quando termina — ou quando alguém interrompe. */
function falar(texto: string): Promise<void> {
    const limpo = textoSemMarkdown(texto);
    if (!sinteseDeVoz || !limpo) return Promise.resolve();

    return new Promise((resolver) => {
        const concluir = (): void => {
            if (encerrarFalaAtual === concluir) encerrarFalaAtual = null;
            resolver();
        };
        encerrarFalaAtual = concluir;

        const fala = new SpeechSynthesisUtterance(limpo);
        fala.lang = vozDaSofia?.lang ?? "pt-BR";
        if (vozDaSofia) fala.voice = vozDaSofia;
        fala.onend = concluir;
        fala.onerror = concluir;

        sinteseDeVoz.cancel();
        sinteseDeVoz.speak(fala);
    });
}

function marcarLeitura(botao: HTMLButtonElement, falando: boolean): void {
    botao.textContent = falando ? "Parar" : "Ouvir";
    botao.setAttribute("aria-pressed", falando ? "true" : "false");
    botao.classList.toggle("falando", falando);
}

/**
 * Cala a Sofia agora. Resolve a espera na mão porque nem todo navegador
 * dispara `end` na fala que foi cancelada.
 */
function pararFala(): void {
    const concluir = encerrarFalaAtual;
    encerrarFalaAtual = null;
    mensagemFalando = null;
    sinteseDeVoz?.cancel();
    concluir?.();
    todos<HTMLButtonElement>("[data-ouvir]", chat).forEach((botao) => {
        marcarLeitura(botao, false);
    });
}

async function alternarLeitura(botao: HTMLButtonElement): Promise<void> {
    const indice = Number(dado(botao, "ouvir"));
    const estavaFalando = mensagemFalando === indice;
    pararFala();
    if (estavaFalando) return;

    const texto = conversaAberta()?.mensagens[indice]?.texto ?? "";
    if (!texto) return;

    mensagemFalando = indice;
    marcarLeitura(botao, true);
    await falar(texto);
    if (mensagemFalando !== indice) return;
    mensagemFalando = null;
    marcarLeitura(botao, false);
}

function mensagemDe(autor: AutorMensagem, texto: string, voz: boolean): MensagemSofia {
    return voz ? { autor, texto, voz: true } : { autor, texto };
}

function htmlMensagem(mensagem: MensagemSofia, indice: number): string {
    if (mensagem.autor === "user") {
        if (mensagem.voz) {
            return `
              <div class="message user message-voz">
                <div class="message-texto">
                  <span class="sofia-voz-etiqueta">${icone("mic")} Áudio</span>
                  <p>${escapar(mensagem.texto)}</p>
                </div>
              </div>
            `;
        }

        return `<div class="message user"><div class="message-texto">${escapar(mensagem.texto)}</div></div>`;
    }

    const ouvir = sinteseDeVoz
        ? `<button type="button" class="sofia-ouvir" data-ouvir="${indice}" aria-pressed="false">Ouvir</button>`
        : "";

    return `
      <div class="message bot">
        <span class="sofia-avatar" aria-hidden="true">S</span>
        <div class="message-corpo">
          <div class="message-texto">${htmlDaSofia(mensagem.texto)}</div>
          <div class="message-acoes">
            <button type="button" class="sofia-copiar" data-copiar="${indice}">Copiar</button>
            ${ouvir}
          </div>
        </div>
      </div>
    `;
}

function htmlAudioPendente(): string {
    return `
      <div class="message user message-voz">
        <div class="message-texto">
          <span class="sofia-voz-etiqueta">${icone("mic")} Áudio</span>
          <p>Transcrevendo áudio…</p>
        </div>
      </div>
    `;
}

function renderConversa(): void {
    const aberta = conversaAberta();
    const mensagens = aberta?.mensagens ?? [];
    const pensando = aberta !== null && aguardando.has(aberta.id);
    const transcrevendo = aberta !== null && transcrevendoId === aberta.id;
    const conversando = mensagens.length > 0 || pensando || transcrevendo;

    chat.innerHTML =
        mensagens.map(htmlMensagem).join("") +
        (transcrevendo ? htmlAudioPendente() : "") +
        (pensando
            ? `<div class="message bot"><span class="sofia-avatar" aria-hidden="true">S</span><div class="message-corpo"><div class="message-texto"><span class="sofia-digitando"><i></i><i></i><i></i></span></div></div></div>`
            : "");

    desenharIcones(chat);
    /* O HTML foi refeito: o botão da mensagem que está sendo lida precisa
       voltar a mostrar "Parar", senão o clique seguinte recomeçaria a leitura. */
    if (mensagemFalando !== null) {
        const botao = talvez<HTMLButtonElement>(
            `[data-ouvir="${mensagemFalando}"]`,
            chat,
        );
        if (botao) marcarLeitura(botao, true);
    }
    chat.classList.toggle("active", conversando);
    coluna.classList.toggle("em-conversa", conversando);
    coluna.classList.toggle("enviando-audio", transcrevendo);
    boasVindas.hidden = conversando;
    titulo.hidden = !conversando;
    titulo.textContent = aberta?.titulo ?? "Novo chat";

    if (conversando) chat.scrollTop = chat.scrollHeight;
    atualizarEnvio();
}

function previaDa(conversa: ConversaSofia): string {
    const ultima = conversa.mensagens.at(-1);
    if (!ultima) return "Chat novo";

    const texto = ultima.texto.replace(/\s+/g, " ").trim();
    const prefixo = ultima.autor === "bot" ? "Sofia: " : ultima.voz ? "Áudio: " : "";
    const linha = `${prefixo}${texto}`;
    return linha.length > 42 ? `${linha.slice(0, 39).trimEnd()}…` : linha;
}

function renderHistorico(): void {
    const { conversaAtiva, conversas } = obterSecao("sofia");
    const palavras = termoDeBusca(buscaChats.value);
    const ordem = [...conversas]
        .sort((a, b) => b.atualizadoEm - a.atualizadoEm)
        .filter((conversa) => combina(conversa.titulo, palavras));

    if (!ordem.length) {
        lista.innerHTML = `<p class="sofia-historico-vazio">${
            palavras.length ? "Nenhum chat com esse nome." : "Nenhum chat ainda."
        }</p>`;
        return;
    }

    lista.innerHTML = ordem
        .map((conversa) => {
            const ativa = conversa.id === conversaAtiva ? " ativa" : "";
            return `
              <div class="sofia-conversa${ativa}">
                <button type="button" class="sofia-conversa-abrir" data-conversa="${escapar(conversa.id)}">
                  <span class="sofia-conversa-titulo">${escapar(conversa.titulo)}</span>
                  <span class="sofia-conversa-previa">${escapar(previaDa(conversa))}</span>
                </button>
                <button
                  type="button"
                  class="icon-button sofia-conversa-apagar"
                  data-apagar="${escapar(conversa.id)}"
                  aria-label="Apagar chat ${escapar(conversa.titulo)}"
                >
                  ${icone("trash-2")}
                </button>
              </div>
            `;
        })
        .join("");

    desenharIcones(lista);
}

function anexar(id: string, autor: AutorMensagem, texto: string, voz = false): void {
    atualizarSecao("sofia", (atual) => ({
        conversas: atual.conversas
            .map((conversa) => {
                if (conversa.id !== id) return conversa;
                const mensagens = [...conversa.mensagens, mensagemDe(autor, texto, voz)];
                const primeiraPergunta = !conversa.mensagens.some(
                    (mensagem) => mensagem.autor === "user",
                );
                return {
                    ...conversa,
                    mensagens,
                    titulo:
                        autor === "user" && primeiraPergunta
                            ? tituloDaConversa(mensagens)
                            : conversa.titulo,
                    atualizadoEm: Date.now(),
                };
            })
            .sort((a, b) => b.atualizadoEm - a.atualizadoEm),
    }));
}

function conversaAindaExiste(id: string): boolean {
    return obterSecao("sofia").conversas.some((conversa) => conversa.id === id);
}

/** Garante um chat para a pergunta e devolve o id dele. */
function registrarPergunta(texto: string, voz = false): string {
    const atual = obterSecao("sofia");
    const existente = atual.conversas.find(
        (conversa) => conversa.id === atual.conversaAtiva,
    );

    if (existente) {
        anexar(existente.id, "user", texto, voz);
        return existente.id;
    }

    const id = novoId();
    const mensagem = mensagemDe("user", texto, voz);
    atualizarSecao("sofia", (estado) => ({
        conversaAtiva: id,
        conversas: [
            {
                id,
                titulo: tituloDaConversa([mensagem]),
                atualizadoEm: Date.now(),
                mensagens: [mensagem],
            },
            ...estado.conversas,
        ],
    }));
    return id;
}

function podePerguntar(pergunta: string): boolean {
    const aberta = conversaAberta();
    return (
        pergunta.trim() !== "" &&
        !(aberta !== null && aguardando.has(aberta.id)) &&
        !modoGravacao
    );
}

/**
 * Registra a pergunta, consulta a base e devolve a resposta — a conversa por
 * voz precisa do texto de volta para ler em voz alta.
 */
async function enviarPergunta(pergunta: string, voz: boolean): Promise<string | null> {
    const texto = pergunta.trim();
    if (!podePerguntar(texto)) return null;

    limparAviso();
    const id = registrarPergunta(texto, voz);
    aguardando.add(id);
    renderConversa();

    const conversa = obterSecao("sofia").conversas.find((item) => item.id === id);
    const historico = historicoDaConversa((conversa?.mensagens ?? []).slice(0, -1));

    let resposta = "";
    let comecou = false;
    const { modo } = obterSecao("sofia");

    try {
        const resultado = await perguntarSofia(texto, historico, {
            modo,
            conversaId: id,
            aoToken(token) {
                if (!conversaAindaExiste(id)) return;
                resposta += token;
                if (!comecou) {
                    comecou = true;
                    aguardando.delete(id);
                    anexar(id, "bot", resposta);
                    return;
                }
                substituirUltima(id, resposta);
            },
        });
        resposta = resultado.resposta;
        if (!conversaAindaExiste(id)) return null;
        if (!comecou) {
            aguardando.delete(id);
            anexar(id, "bot", resposta);
        } else {
            substituirUltima(id, resposta);
        }
    } catch (erro) {
        resposta =
            erro instanceof ErroDeApi
                ? erro.message
                : "Não consegui consultar a base agora. Tente novamente em instantes.";
        aguardando.delete(id);
        if (!conversaAindaExiste(id)) return null;
        if (comecou) {
            substituirUltima(id, resposta);
        } else {
            anexar(id, "bot", resposta);
        }
    }

    return resposta;
}

async function perguntar(pergunta: string, voz = false): Promise<void> {
    if (!podePerguntar(pergunta)) return;
    entrada.value = "";
    ajustarCampo();
    await enviarPergunta(pergunta, voz);
}

async function enviarBlob(arquivo: Blob, nome: string): Promise<void> {
    if (!transcricaoNoServidor || esperandoResposta() || transcrevendoId !== null) return;

    limparAviso();
    const aberta = conversaAberta();
    const id = aberta?.id ?? novoId();
    transcrevendoId = id;
    aguardando.add(id);

    if (!aberta) {
        atualizarSecao("sofia", (estado) => ({
            conversaAtiva: id,
            conversas: [
                {
                    id,
                    titulo: "Novo chat",
                    atualizadoEm: Date.now(),
                    mensagens: [],
                },
                ...estado.conversas,
            ],
        }));
    } else {
        renderConversa();
    }

    try {
        const historico = historicoDaConversa(
            obterSecao("sofia").conversas.find((item) => item.id === id)?.mensagens ?? [],
        );
        const resultado = await enviarAudioSofia(arquivo, nome, historico, {
            modo: obterSecao("sofia").modo,
            conversaId: id,
        });
        transcrevendoId = null;
        aguardando.delete(id);
        if (!conversaAindaExiste(id)) return;

        const texto = resultado.transcricao.trim();
        if (!texto) {
            anexar(id, "user", "Mensagem de áudio", true);
            anexar(
                id,
                "bot",
                "Não consegui entender o áudio. Grave de novo ou escreva a pergunta.",
            );
            return;
        }

        anexar(id, "user", texto, true);
        anexar(id, "bot", resultado.resposta);
    } catch (erro) {
        transcrevendoId = null;
        aguardando.delete(id);
        if (!conversaAindaExiste(id)) return;

        const mensagem =
            erro instanceof ErroDeApi
                ? (erro.primeiroErro("audio") ?? erro.message)
                : "Não consegui enviar o áudio. Tente novamente em instantes.";
        anexar(id, "user", "Mensagem de áudio", true);
        anexar(id, "bot", mensagem);
    }
}

function novoChat(): void {
    sairDaConversaPorVoz();
    cancelarGravacao();
    pararFala();
    limparAnexo();
    limparAviso();
    if (obterSecao("sofia").conversaAtiva !== null) {
        atualizarSecao("sofia", { conversaAtiva: null });
    }
    entrada.value = "";
    ajustarCampo();
    fecharHistorico();
    renderConversa();
    entrada.focus();
}

function abrirConversa(id: string): void {
    sairDaConversaPorVoz();
    cancelarGravacao();
    pararFala();
    limparAviso();
    atualizarSecao("sofia", { conversaAtiva: id });
    fecharHistorico();
    entrada.focus();
}

function substituirUltima(id: string, texto: string): void {
    atualizarSecao("sofia", (atual) => ({
        conversas: atual.conversas.map((conversa) => {
            if (conversa.id !== id || conversa.mensagens.length === 0) return conversa;
            const mensagens = [...conversa.mensagens];
            const ultima = mensagens[mensagens.length - 1];
            if (!ultima || ultima.autor !== "bot") return conversa;
            mensagens[mensagens.length - 1] = { ...ultima, texto };
            return { ...conversa, mensagens, atualizadoEm: Date.now() };
        }),
    }));
}

function aplicarConversas(conversas: ConversaSofia[]): void {
    const ativa = obterSecao("sofia").conversaAtiva;
    atualizarSecao("sofia", {
        conversas,
        conversaAtiva: conversas.some((conversa) => conversa.id === ativa) ? ativa : null,
    });
}

async function hidratarConversas(): Promise<void> {
    try {
        const servidor = await carregarConversasSofia();
        const locais = obterSecao("sofia").conversas;
        if (servidor.length === 0 && locais.length > 0) {
            aplicarConversas(
                await importarConversasSofia(locais, obterSecao("sofia").modo),
            );
            return;
        }
        if (servidor.length > 0) {
            aplicarConversas(servidor);
        }
    } catch {
        // Sem o servidor, o cache do navegador continua valendo.
    }
}

function apagarConversa(id: string): void {
    aguardando.delete(id);
    if (transcrevendoId === id) transcrevendoId = null;
    void apagarConversaSofia(id).catch(() => undefined);
    atualizarSecao("sofia", (atual) => {
        const conversas = atual.conversas.filter((conversa) => conversa.id !== id);
        return {
            conversas,
            conversaAtiva: atual.conversaAtiva === id ? null : atual.conversaAtiva,
        };
    });
}

function htmlDaSofia(texto: string): string {
    return htmlDeMarkdown(texto);
}

function aplicarModo(modo: ModoSofia): void {
    modoPesquisa.classList.toggle("active", modo === "search");
    modoBase.classList.toggle("active", modo === "base");
    boasVindasTexto.textContent =
        modo === "search"
            ? "Modo Pesquisar: busca ampla na base — documentos, índice interno, empresas e o restante do HC Brain."
            : "Modo Base HC: consulta os cadastros — reuniões, clientes, financeiro, projetos e processos.";
}

function enviarFormulario(): void {
    if (modoGravacao) {
        pararEEnviar();
        return;
    }

    if (audioAnexado) {
        const arquivo = audioAnexado;
        limparAnexo();
        void enviarBlob(arquivo, arquivo.name);
        return;
    }

    void perguntar(entrada.value);
}

function reconhecimentoDisponivel(): ConstrutorDeReconhecimento | null {
    const janela = window as unknown as {
        SpeechRecognition?: ConstrutorDeReconhecimento;
        webkitSpeechRecognition?: ConstrutorDeReconhecimento;
    };
    return janela.SpeechRecognition ?? janela.webkitSpeechRecognition ?? null;
}

function formatarTempo(totalSegundos: number): string {
    const minutos = Math.floor(totalSegundos / 60);
    const segundos = totalSegundos % 60;
    return `${minutos}:${segundos.toString().padStart(2, "0")}`;
}

function pararStream(): void {
    streamMic?.getTracks().forEach((faixa) => faixa.stop());
    streamMic = null;
}

function abrirPainel(aoVivo: boolean): void {
    coluna.classList.add("gravando");
    gravacao.hidden = false;
    /* Parar para editar só faz sentido no ditado: no caminho do servidor o
       texto só existe depois do envio. */
    gravacaoParar.hidden = !aoVivo;
    gravacaoEstado.textContent = aoVivo ? "Ouvindo…" : "Gravando…";
    gravacaoTempo.textContent = "0:00";
    gravacaoParcial.textContent = aoVivo
        ? "Fale sua pergunta."
        : "Gravando. O áudio será transcrito quando você enviar.";
    micBtn.setAttribute("aria-pressed", "true");
    micBtn.setAttribute(
        "aria-label",
        aoVivo ? "Pausar o ditado e editar o texto" : "Parar gravação e enviar",
    );
    inicioGravacao = Date.now();
    if (timerGravacao !== null) window.clearInterval(timerGravacao);
    timerGravacao = window.setInterval(() => {
        const segundos = Math.floor((Date.now() - inicioGravacao) / 1000);
        gravacaoTempo.textContent = formatarTempo(segundos);
        /* No limite o ditado pausa em vez de enviar: a pergunta não sai sem a
           pessoa ver o que foi entendido. */
        if (Date.now() - inicioGravacao < LIMITE_GRAVACAO_MS) return;
        if (aoVivo) pausarDitado();
        else pararEEnviar();
    }, 250);
    atualizarEnvio();
    (aoVivo ? gravacaoParar : gravacaoEnviar).focus();
}

function encerrarPainel(): void {
    if (timerGravacao !== null) {
        window.clearInterval(timerGravacao);
        timerGravacao = null;
    }
    reconhecimentoAtivo = null;
    gravador = null;
    modoGravacao = null;
    paradaPedida = false;
    pausaParaEditar = false;
    pararStream();
    gravacao.hidden = true;
    coluna.classList.remove("gravando");
    micBtn.setAttribute("aria-pressed", "false");
    micBtn.setAttribute("aria-label", "Gravar pergunta em áudio");
    atualizarEnvio();
}

function textoOuvido(): string {
    return `${transcricaoFinal} ${transcricaoParcial}`.replace(/\s+/g, " ").trim();
}

/** Retomar o ditado soma ao que já está escrito, em vez de trocar o campo. */
function juntarTextos(anterior: string, novo: string): string {
    const base = anterior.replace(/\s+$/, "");
    if (!base) return novo;
    if (!novo) return base;
    return `${base} ${novo}`;
}

function focarFimDoCampo(): void {
    entrada.focus();
    const fim = entrada.value.length;
    entrada.setSelectionRange(fim, fim);
}

function pararEEnviar(): void {
    if (!modoGravacao || paradaPedida) return;
    paradaPedida = true;
    cancelouGravacao = false;

    if (reconhecimentoAtivo) {
        reconhecimentoAtivo.stop();
        return;
    }

    if (gravador && gravador.state !== "inactive") {
        gravador.stop();
    }
}

/** Encerra a escuta e devolve o texto ao campo, sem enviar nada. */
function pausarDitado(): void {
    if (modoGravacao !== "fala" || paradaPedida) return;
    paradaPedida = true;
    pausaParaEditar = true;
    cancelouGravacao = false;
    reconhecimentoAtivo?.stop();
}

function cancelarGravacao(): void {
    if (!modoGravacao) return;
    cancelouGravacao = true;
    paradaPedida = true;

    if (reconhecimentoAtivo) {
        reconhecimentoAtivo.abort();
        return;
    }

    if (gravador && gravador.state !== "inactive") {
        gravador.stop();
        return;
    }

    encerrarPainel();
    entrada.focus();
}

function avisoDoMicrofone(nome: string): string {
    if (nome === "NotAllowedError" || nome === "PermissionDeniedError") {
        return "O microfone está bloqueado. Permita o acesso nas configurações do navegador e tente de novo.";
    }
    if (nome === "NotFoundError" || nome === "DevicesNotFoundError") {
        return "Não encontrei um microfone neste dispositivo. Anexe um arquivo de áudio ou escreva a pergunta.";
    }
    return "Não foi possível usar o microfone. Tente de novo ou anexe um arquivo de áudio.";
}

function avisoDoReconhecimento(codigo: string): string | null {
    if (codigo === "aborted" || codigo === "no-speech") return null;
    if (codigo === "not-allowed") {
        return "O microfone está bloqueado. Permita o acesso nas configurações do navegador e tente de novo.";
    }
    if (codigo === "audio-capture") {
        return "Não encontrei um microfone neste dispositivo. Anexe um arquivo de áudio ou escreva a pergunta.";
    }
    if (codigo === "network" || codigo === "service-not-allowed") {
        return "O reconhecimento de voz não está disponível neste navegador. Anexe um arquivo de áudio ou escreva a pergunta.";
    }
    return "Não consegui ouvir o áudio. Tente de novo ou anexe um arquivo de áudio.";
}

function iniciarReconhecimento(Construtor: ConstrutorDeReconhecimento): void {
    transcricaoFinal = "";
    transcricaoParcial = "";
    cancelouGravacao = false;
    paradaPedida = false;
    modoGravacao = "fala";

    const reconhecimento = new Construtor();
    reconhecimento.lang = "pt-BR";
    reconhecimento.continuous = true;
    reconhecimento.interimResults = true;
    reconhecimento.onresult = (evento) => {
        let parcial = "";
        for (
            let indice = evento.resultIndex;
            indice < evento.results.length;
            indice += 1
        ) {
            const resultado = evento.results[indice];
            const trecho = resultado?.[0]?.transcript ?? "";
            if (resultado?.isFinal) transcricaoFinal = `${transcricaoFinal} ${trecho}`;
            else parcial = trecho;
        }
        transcricaoParcial = parcial;
        const ouvido = textoOuvido();
        gravacaoParcial.textContent = ouvido || "Fale sua pergunta.";
    };
    reconhecimento.onerror = (evento) => {
        const texto = avisoDoReconhecimento(evento.error);
        if (!texto) return;
        cancelouGravacao = true;
        mostrarAviso(texto);
    };
    reconhecimento.onend = () => {
        if (modoGravacao !== "fala") return;
        const ouvido = textoOuvido();
        const cancelou = cancelouGravacao;
        const paraEditar = pausaParaEditar;
        encerrarPainel();

        /* Cancelar não encosta no campo: o que já estava escrito continua lá. */
        if (cancelou) {
            focarFimDoCampo();
            return;
        }

        if (!ouvido) {
            focarFimDoCampo();
            mostrarAviso("Não ouvi nada. Fale de novo ou escreva a pergunta.");
            return;
        }

        const texto = juntarTextos(entrada.value, ouvido);

        if (paraEditar) {
            entrada.value = texto;
            ajustarCampo();
            atualizarEnvio();
            focarFimDoCampo();
            return;
        }

        void perguntar(texto, true);
    };

    reconhecimentoAtivo = reconhecimento;
    abrirPainel(true);

    try {
        reconhecimento.start();
    } catch {
        encerrarPainel();
        mostrarAviso(
            "Não consegui começar a gravação. Anexe um arquivo de áudio ou escreva a pergunta.",
        );
        entrada.focus();
    }
}

function tipoDeGravacao(): string {
    if (typeof MediaRecorder === "undefined") return "";
    const candidatos = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg"];
    return candidatos.find((tipo) => MediaRecorder.isTypeSupported(tipo)) ?? "";
}

function extensaoDoTipo(tipo: string): string {
    if (tipo.includes("mp4")) return "m4a";
    if (tipo.includes("ogg")) return "ogg";
    return "webm";
}

async function iniciarGravador(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
        mostrarAviso(
            "Este navegador não grava áudio. Anexe um arquivo de áudio ou escreva a pergunta.",
        );
        return;
    }

    cancelouGravacao = false;
    paradaPedida = false;
    pedacos = [];

    try {
        streamMic = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (erro) {
        const nome = erro instanceof DOMException ? erro.name : "";
        mostrarAviso(avisoDoMicrofone(nome));
        entrada.focus();
        return;
    }

    const tipo = tipoDeGravacao();
    try {
        gravador = tipo
            ? new MediaRecorder(streamMic, { mimeType: tipo })
            : new MediaRecorder(streamMic);
    } catch {
        pararStream();
        mostrarAviso(
            "Este navegador não grava áudio. Anexe um arquivo de áudio ou escreva a pergunta.",
        );
        return;
    }

    modoGravacao = "arquivo";
    const recorder = gravador;
    recorder.ondataavailable = (evento) => {
        if (evento.data.size > 0) pedacos.push(evento.data);
    };
    recorder.onstop = () => {
        const mime = recorder.mimeType || "audio/webm";
        const blob = new Blob(pedacos, { type: mime });
        pedacos = [];
        const cancelou = cancelouGravacao;
        encerrarPainel();
        entrada.focus();
        if (cancelou) return;
        if (!blob.size) {
            mostrarAviso("A gravação ficou vazia. Tente de novo.");
            return;
        }
        void enviarBlob(blob, `pergunta.${extensaoDoTipo(mime)}`);
    };

    recorder.start();
    abrirPainel(false);
}

function explicarSemVoz(): void {
    mostrarAviso(SEM_VOZ);
    entrada.focus();
}

function explicarSemConversaPorVoz(): void {
    mostrarAviso(audioDisponivel ? SEM_CONVERSA_POR_VOZ : SEM_VOZ);
    entrada.focus();
}

function movimentoReduzido(): boolean {
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * Nível 0–1 do que entra pelo microfone, para a esfera acompanhar a voz.
 */
function nivelDoMicrofone(): number {
    if (!analisador || !amostras) return 0;

    analisador.getByteTimeDomainData(amostras);
    let soma = 0;
    for (let indice = 0; indice < amostras.length; indice += 1) {
        const desvio = ((amostras[indice] ?? 128) - 128) / 128;
        soma += desvio * desvio;
    }

    return Math.min(1, Math.sqrt(soma / amostras.length) * 4);
}

/**
 * A síntese de fala não expõe o nível do áudio que está saindo, então esta
 * onda é uma aproximação montada no tempo: a esfera respira enquanto a Sofia
 * fala, mas o desenho não corresponde ao som de verdade.
 */
function nivelAproximadoDaFala(agora: number): number {
    const tempo = (agora - inicioDaFala) / 1000;
    const onda =
        Math.sin(tempo * 7.1) * 0.5 +
        Math.sin(tempo * 11.7) * 0.3 +
        Math.sin(tempo * 3.3) * 0.2;
    return Math.min(1, Math.abs(onda));
}

function animarEsfera(agora: number): void {
    const estado = esfera.dataset.estado;
    const nivel =
        estado === "ouvindo"
            ? nivelDoMicrofone()
            : estado === "falando"
              ? nivelAproximadoDaFala(agora)
              : 0;

    esfera.style.setProperty("--nivel", nivel.toFixed(3));
    quadroDaEsfera = window.requestAnimationFrame(animarEsfera);
}

function pararAnimacaoDaEsfera(): void {
    if (quadroDaEsfera !== null) window.cancelAnimationFrame(quadroDaEsfera);
    quadroDaEsfera = null;
    esfera.style.setProperty("--nivel", "0");
}

/**
 * Liga o analisador do microfone. A permissão é a mesma que o ditado já pede,
 * então o navegador não pergunta de novo onde ela já foi concedida. Se faltar
 * qualquer peça, a esfera segue só com a animação ociosa: nada aqui é
 * essencial para a conversa acontecer.
 */
async function ligarAnalisador(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia || typeof AudioContext === "undefined") {
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        if (!conversaPorVoz) {
            stream.getTracks().forEach((faixa) => faixa.stop());
            return;
        }

        streamDaEsfera = stream;
        contextoDeAudio = new AudioContext();
        analisador = contextoDeAudio.createAnalyser();
        analisador.fftSize = 512;
        analisador.smoothingTimeConstant = 0.8;
        contextoDeAudio.createMediaStreamSource(stream).connect(analisador);
        amostras = new Uint8Array(analisador.frequencyBinCount);
    } catch {
        desligarAnalisador();
    }
}

function desligarAnalisador(): void {
    streamDaEsfera?.getTracks().forEach((faixa) => faixa.stop());
    streamDaEsfera = null;
    analisador = null;
    amostras = null;
    if (contextoDeAudio) void contextoDeAudio.close().catch(() => undefined);
    contextoDeAudio = null;
}

function definirEstadoDeVoz(estado: EstadoDeVoz): void {
    esfera.dataset.estado = estado;
    if (estado === "falando") inicioDaFala = performance.now();
    vozEstado.textContent =
        estado === "ouvindo"
            ? "Ouvindo você…"
            : estado === "pensando"
              ? "Pensando na resposta…"
              : "Sofia falando…";
    vozInterromper.hidden = estado !== "falando";
}

function sairDaConversaPorVoz(): void {
    if (!conversaPorVoz) return;

    /* A bandeira cai antes de tudo: é ela que faz o ciclo parar quando a
       escuta e a fala em andamento forem encerradas logo abaixo. */
    conversaPorVoz = false;
    reconhecimentoDaConversa?.abort();
    reconhecimentoDaConversa = null;
    pararFala();
    pararAnimacaoDaEsfera();
    desligarAnalisador();

    vozTela.hidden = true;
    vozInterromper.hidden = true;
    tela.classList.remove("em-voz");
    vozBtn.classList.remove("ativa");
    vozBtn.setAttribute("aria-pressed", "false");
    vozBtn.setAttribute("aria-label", "Iniciar conversa por voz");
    vozBtn.title = "Iniciar conversa por voz";
    atualizarEnvio();

    const foco = document.activeElement;
    if (foco === document.body || (foco instanceof Node && vozTela.contains(foco))) {
        vozBtn.focus();
    }
}

/**
 * Um turno de escuta. Devolve a pergunta ouvida, texto vazio quando foi só
 * silêncio, ou `null` quando deu erro — e aí a conversa por voz já saiu.
 */
function ouvirTurnoDeVoz(Construtor: ConstrutorDeReconhecimento): Promise<string | null> {
    return new Promise((resolver) => {
        const reconhecimento = new Construtor();
        reconhecimento.lang = "pt-BR";
        /* Sem `continuous`, o reconhecimento termina sozinho na pausa da fala —
           é o que marca o fim da pergunta sem precisar de nenhum botão. */
        reconhecimento.continuous = false;
        reconhecimento.interimResults = true;

        let ouvido = "";
        let falha: string | null = null;

        reconhecimento.onresult = (evento) => {
            let parcial = "";
            for (
                let indice = evento.resultIndex;
                indice < evento.results.length;
                indice += 1
            ) {
                const resultado = evento.results[indice];
                const trecho = resultado?.[0]?.transcript ?? "";
                if (resultado?.isFinal) ouvido = `${ouvido} ${trecho}`;
                else parcial = trecho;
            }
            const texto = `${ouvido} ${parcial}`.replace(/\s+/g, " ").trim();
            vozParcial.textContent = texto || "Fale sua pergunta.";
        };

        reconhecimento.onerror = (evento) => {
            falha = avisoDoReconhecimento(evento.error);
        };

        reconhecimento.onend = () => {
            reconhecimentoDaConversa = null;
            if (falha) {
                mostrarAviso(falha);
                sairDaConversaPorVoz();
                resolver(null);
                return;
            }
            resolver(ouvido.replace(/\s+/g, " ").trim());
        };

        reconhecimentoDaConversa = reconhecimento;

        try {
            reconhecimento.start();
        } catch {
            reconhecimentoDaConversa = null;
            mostrarAviso("Não consegui começar a conversa por voz. Tente de novo.");
            sairDaConversaPorVoz();
            resolver(null);
        }
    });
}

/** Ouvir, perguntar, falar a resposta, ouvir de novo — até alguém sair. */
async function rodarConversaPorVoz(
    Construtor: ConstrutorDeReconhecimento,
): Promise<void> {
    while (conversaPorVoz) {
        definirEstadoDeVoz("ouvindo");
        vozParcial.textContent = "Fale sua pergunta.";

        const pergunta = await ouvirTurnoDeVoz(Construtor);
        if (!conversaPorVoz || pergunta === null) return;

        if (!pergunta) {
            silenciosSeguidos += 1;
            if (silenciosSeguidos < LIMITE_DE_SILENCIOS) continue;
            mostrarAviso(
                "Não ouvi nada. Comece a conversa por voz de novo quando quiser falar.",
            );
            sairDaConversaPorVoz();
            return;
        }

        silenciosSeguidos = 0;
        definirEstadoDeVoz("pensando");
        vozParcial.textContent = pergunta;

        const resposta = await enviarPergunta(pergunta, true);
        if (!conversaPorVoz) return;
        if (resposta === null) continue;

        /* A resposta inteira já está na conversa; repeti-la aqui empurraria o
           chat para fora da tela bem na hora de ler. */
        definirEstadoDeVoz("falando");
        vozParcial.textContent = "A resposta está na conversa. Interrompa quando quiser.";
        await falar(resposta);
    }
}

function entrarNaConversaPorVoz(): void {
    if (conversaPorVoz || modoGravacao || esperandoResposta()) return;

    const Construtor = reconhecimentoDisponivel();
    if (!Construtor) {
        explicarSemConversaPorVoz();
        return;
    }

    limparAviso();
    pararFala();
    conversaPorVoz = true;
    silenciosSeguidos = 0;

    vozTela.hidden = false;
    tela.classList.add("em-voz");
    vozBtn.classList.add("ativa");
    vozBtn.setAttribute("aria-pressed", "true");
    vozBtn.setAttribute("aria-label", "Sair da conversa por voz");
    vozBtn.title = "Sair da conversa por voz";
    atualizarEnvio();

    /* Com movimento reduzido a esfera fica parada, então não há por que abrir
       o microfone só para medir o nível. */
    if (!movimentoReduzido()) {
        quadroDaEsfera = window.requestAnimationFrame(animarEsfera);
        void ligarAnalisador();
    }

    void rodarConversaPorVoz(Construtor);
}

function iniciarGravacao(): void {
    if (modoGravacao || esperandoResposta()) return;

    if (!audioDisponivel) {
        explicarSemVoz();
        return;
    }

    limparAviso();

    const Construtor = reconhecimentoDisponivel();
    if (Construtor) {
        iniciarReconhecimento(Construtor);
        return;
    }

    void iniciarGravador();
}

/**
 * O que a tela oferece depende do que existe de verdade: sem reconhecimento
 * no navegador e sem transcrição no servidor, o microfone fica desabilitado
 * e o anexo some — nenhum áudio chega a sair daqui. A conversa por voz é mais
 * exigente: ela depende do reconhecimento, que a transcrição no servidor não
 * substitui, porque ninguém fica apertando botão numa conversa sem as mãos.
 */
function prepararEntradaDeVoz(): void {
    anexarBtn.hidden = !transcricaoNoServidor;

    if (!ditadoNoNavegador) {
        const explicacao = audioDisponivel ? SEM_CONVERSA_POR_VOZ : SEM_VOZ;
        vozWrap.classList.add("sem-voz");
        vozBtn.setAttribute("aria-disabled", "true");
        vozBtn.setAttribute("aria-describedby", "sofiaDicaVoz");
        vozBtn.setAttribute(
            "aria-label",
            "Iniciar conversa por voz — indisponível neste navegador",
        );
        vozBtn.title = explicacao;
        dicaVoz.textContent = explicacao;
        dicaVoz.hidden = false;
    }

    if (audioDisponivel) return;

    micWrap.classList.add("sem-voz");
    micBtn.setAttribute("aria-disabled", "true");
    micBtn.setAttribute("aria-describedby", "sofiaDicaVoz");
    micBtn.setAttribute(
        "aria-label",
        "Gravar pergunta em áudio — indisponível neste navegador",
    );
    micBtn.title = SEM_VOZ;
    dicaVoz.textContent = SEM_VOZ;
    dicaVoz.hidden = false;
    entrada.placeholder = "Pergunte à Sofia";
}

/** O que a janela "Modelo da Sofia" conta sobre o áudio neste ambiente. */
function explicacaoDoAudio(): string {
    if (!audioDisponivel) {
        return "Neste navegador a pergunta vai por texto: ele não reconhece voz e o ambiente não tem transcrição configurada.";
    }
    if (!transcricaoNoServidor) {
        return "O microfone envia a pergunta falada.";
    }
    return "O microfone envia a pergunta falada. Um arquivo de áudio é transcrito no servidor.";
}

function arquivoEhAudio(arquivo: File): boolean {
    if (arquivo.type.startsWith("audio/") || arquivo.type === "video/webm") return true;
    return /\.(webm|mp3|mpeg|mpga|m4a|mp4|wav|ogg|oga)$/i.test(arquivo.name);
}

porId<HTMLFormElement>("chatForm").addEventListener("submit", (evento) => {
    evento.preventDefault();
    enviarFormulario();
});

entrada.addEventListener("input", () => {
    ajustarCampo();
    atualizarEnvio();
});

entrada.addEventListener("keydown", (evento) => {
    if (evento.key === "Enter" && !evento.shiftKey) {
        evento.preventDefault();
        enviarFormulario();
    }
});

porId("sofiaNovaConversa").addEventListener("click", novoChat);
porId("sofiaAbrirHistorico").addEventListener("click", abrirHistorico);
fundoHistorico.addEventListener("click", fecharHistorico);

porId("sofiaBuscaBtn").addEventListener("click", () => {
    buscaWrap.hidden = !buscaWrap.hidden;
    if (buscaWrap.hidden) {
        buscaChats.value = "";
        renderHistorico();
        return;
    }
    buscaChats.focus();
});

buscaChats.addEventListener("input", () => renderHistorico());

chat.addEventListener("click", (evento) => {
    const leitura = alvoMaisProximo<HTMLButtonElement>(evento, "[data-ouvir]");
    if (leitura) {
        void alternarLeitura(leitura);
        return;
    }

    const botao = alvoMaisProximo<HTMLButtonElement>(evento, "[data-copiar]");
    if (!botao) return;
    const indice = Number(dado(botao, "copiar"));
    const texto = conversaAberta()?.mensagens[indice]?.texto ?? "";
    if (!texto) return;

    void navigator.clipboard.writeText(texto).then(() => {
        botao.textContent = "Copiado";
        botao.classList.add("copiado");
        window.setTimeout(() => {
            botao.textContent = "Copiar";
            botao.classList.remove("copiado");
        }, 1500);
    });
});

lista.addEventListener("click", (evento) => {
    const apagar = alvoMaisProximo(evento, "[data-apagar]");
    if (apagar) {
        apagarConversa(dado(apagar, "apagar"));
        return;
    }

    const abrir = alvoMaisProximo(evento, "[data-conversa]");
    if (abrir) abrirConversa(dado(abrir, "conversa"));
});

anexarBtn.addEventListener("click", () => arquivos.click());

arquivos.addEventListener("change", () => {
    const selecionados = Array.from(arquivos.files ?? []);
    if (!selecionados.length) return;

    const audio = selecionados.find(arquivoEhAudio) ?? null;
    if (!audio) {
        arquivos.value = "";
        mostrarAviso(
            "Anexe um áudio em webm, mp3, m4a, wav ou ogg — ou escreva a pergunta.",
        );
        return;
    }

    audioAnexado = audio;
    anexo.classList.add("show");
    anexoNome.textContent = `Áudio · ${audio.name}`;
    limparAviso();
    atualizarEnvio();
});

porId("removeSofiaAttachment").addEventListener("click", () => {
    limparAnexo();
});

modoPesquisa.addEventListener("click", () => atualizarSecao("sofia", { modo: "search" }));
modoBase.addEventListener("click", () => atualizarSecao("sofia", { modo: "base" }));

porId("sofiaModelBtn").addEventListener("click", () => {
    showModal(
        "Modelo da Sofia",
        `A Sofia responde a partir da base da HC: empresas, sócios, reuniões, documentos, financeiro, treinamentos, projetos e processos. Pesquisar faz a busca ampla; Base HC consulta os cadastros. ${explicacaoDoAudio()} Ela não consulta nada fora daqui.`,
    );
});

/* No ditado o microfone vira pausa: a fala para e o texto fica no campo. */
micBtn.addEventListener("click", () => {
    if (modoGravacao === "fala") {
        pausarDitado();
        return;
    }
    if (modoGravacao) {
        pararEEnviar();
        return;
    }
    iniciarGravacao();
});

/* O microfone desabilitado não recebe clique, então quem responde é o
   invólucro — e a explicação chega de qualquer jeito. */
micWrap.addEventListener("click", () => {
    if (audioDisponivel) return;
    explicarSemVoz();
});

vozBtn.addEventListener("click", () => {
    if (conversaPorVoz) {
        sairDaConversaPorVoz();
        return;
    }
    entrarNaConversaPorVoz();
});

vozWrap.addEventListener("click", () => {
    if (ditadoNoNavegador) return;
    explicarSemConversaPorVoz();
});

vozInterromper.addEventListener("click", () => {
    pararFala();
});

vozFechar.addEventListener("click", sairDaConversaPorVoz);

porId("sofiaGravacaoCancelar").addEventListener("click", () => {
    cancelarGravacao();
});

gravacaoParar.addEventListener("click", () => {
    pausarDitado();
});

gravacaoEnviar.addEventListener("click", () => {
    pararEEnviar();
});

document.addEventListener("keydown", (evento) => {
    if (evento.key !== "Escape") return;
    if (modoGravacao) {
        evento.preventDefault();
        cancelarGravacao();
        return;
    }
    if (conversaPorVoz) {
        evento.preventDefault();
        sairDaConversaPorVoz();
        return;
    }
    if (!buscaWrap.hidden) {
        buscaWrap.hidden = true;
        buscaChats.value = "";
        renderHistorico();
        return;
    }
    fecharHistorico();
});

/* Sair da página não pode deixar o microfone aberto nem a Sofia falando
   sozinha: `pagehide` cobre tanto a navegação quanto o fechamento da aba. */
window.addEventListener("pagehide", () => {
    sairDaConversaPorVoz();
    cancelarGravacao();
    pararFala();
    pararStream();
    desligarAnalisador();
    if (timerGravacao !== null) {
        window.clearInterval(timerGravacao);
        timerGravacao = null;
    }
});

observarEstado(["sofia"], () => {
    aplicarModo(obterSecao("sofia").modo);
    renderHistorico();
    renderConversa();
});

if (sinteseDeVoz) {
    escolherVozPortuguesa();
    sinteseDeVoz.addEventListener("voiceschanged", escolherVozPortuguesa);
}

prepararEntradaDeVoz();
aplicarModo(obterSecao("sofia").modo);
await hidratarConversas();
renderHistorico();
renderConversa();
entrada.focus();
