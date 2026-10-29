/* =========================================================
   AGENTE SOFIA — DOCK LATERAL
   Chat limpo à direita. O histórico abre num painel por cima,
   como no ChatGPT — não fica espremido no meio da conversa.
   ========================================================= */

import { ErroDeApi } from "./api.ts";
import {
    apagarConversaSofia,
    carregarConversasSofia,
    historicoDaConversa,
    perguntarSofia,
    tituloDaConversa,
    type ConversaSofia,
    type MensagemSofia,
} from "../dados/sofia.ts";
import { dado, porSeletor, talvez } from "./dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "./estado.ts";
import { escapar } from "./formato.ts";
import { desenharIcones, icone } from "./icones.ts";
import { htmlDeMarkdown } from "./markdown.ts";
import { caminhoDaPagina } from "./paginas.ts";

const PAINEL_ID = "sofiaAgente";

let paginaAtual = "";
let enviando = false;
let hidratou = false;
/** Lista de chats aberta por cima do chat atual. */
let historicoAberto = false;

export function montarSofiaAgente(paginaId: string): void {
    if (paginaId === "sofia-ia") {
        document.body.classList.remove("sofia-dock-aberta");
        return;
    }

    if (talvez(`#${PAINEL_ID}`)) {
        return;
    }

    paginaAtual = paginaId;

    const raiz = document.createElement("div");
    raiz.id = PAINEL_ID;
    raiz.className = "sofia-agente";
    raiz.innerHTML = `
      <button type="button" class="sofia-agente-fab" data-sofia-abrir title="Abrir Sofia" aria-expanded="false" aria-controls="sofiaAgenteDock">
        <span class="sofia-agente-fab-marca" aria-hidden="true">S</span>
        <span class="sr-only">Abrir Sofia</span>
      </button>

      <aside class="sofia-agente-dock" id="sofiaAgenteDock" aria-label="Sofia, assistente do HC Brain">
        <header class="sofia-agente-topo">
          <div class="sofia-agente-topo-esq">
            <button type="button" class="icon-button" data-sofia-historico title="Ver chats" aria-label="Ver chats" aria-expanded="false">
              ${icone("list")}
            </button>
            <div class="sofia-agente-topo-info">
              <strong data-sofia-titulo>Novo chat</strong>
            </div>
          </div>
          <div class="sofia-agente-topo-acoes">
            <button type="button" class="icon-button" data-sofia-nova title="Novo chat" aria-label="Novo chat">
              ${icone("plus")}
            </button>
            <a class="icon-button" href="${caminhoDaPagina("sofia-ia")}" title="Abrir em tela cheia" aria-label="Abrir em tela cheia">
              ${icone("maximize-2")}
            </a>
            <button type="button" class="icon-button" data-sofia-fechar aria-label="Fechar Sofia">
              ${icone("x")}
            </button>
          </div>
        </header>

        <div class="sofia-agente-modos" role="group" aria-label="Modo da Sofia">
          <button type="button" data-sofia-modo="search">Pesquisar</button>
          <button type="button" data-sofia-modo="base">Base HC</button>
        </div>

        <div class="sofia-agente-corpo">
          <div class="sofia-agente-chat" data-sofia-chat aria-live="polite"></div>

          <div class="sofia-agente-lista" data-sofia-lista hidden>
            <div class="sofia-agente-lista-topo">
              <strong>Seus chats</strong>
              <button type="button" class="secondary sofia-agente-lista-novo" data-sofia-nova-lista>
                ${icone("plus")}
                <span>Novo chat</span>
              </button>
            </div>
            <div class="sofia-agente-conversas" data-sofia-conversas></div>
          </div>
        </div>

        <form class="sofia-agente-form" data-sofia-form>
          <label class="sr-only" for="sofiaAgentePergunta">Pergunta para a Sofia</label>
          <textarea
            id="sofiaAgentePergunta"
            data-sofia-entrada
            rows="1"
            placeholder="Pergunte à Sofia…"
            autocomplete="off"
          ></textarea>
          <button type="submit" class="sofia-agente-enviar" data-sofia-enviar aria-label="Enviar" disabled>
            ${icone("arrow-up")}
          </button>
        </form>
      </aside>
    `;

    document.body.appendChild(raiz);
    desenharIcones(raiz);
    ligar(raiz);
    aplicarDock(obterSecao("sofia").agenteAberto);
    render();

    observarEstado(["sofia"], () => {
        aplicarDock(obterSecao("sofia").agenteAberto);
        render();
    });

    if (obterSecao("sofia").agenteAberto) {
        void hidratar();
    }
}

function ligar(raiz: HTMLElement): void {
    const entrada = porSeletor<HTMLTextAreaElement>("[data-sofia-entrada]", raiz);
    const enviar = porSeletor<HTMLButtonElement>("[data-sofia-enviar]", raiz);

    porSeletor("[data-sofia-abrir]", raiz).addEventListener("click", () => {
        atualizarSecao("sofia", { agenteAberto: true });
        void hidratar();
        entrada.focus();
    });

    porSeletor("[data-sofia-fechar]", raiz).addEventListener("click", () => {
        historicoAberto = false;
        atualizarSecao("sofia", { agenteAberto: false });
    });

    const novoChat = (): void => {
        historicoAberto = false;
        atualizarSecao("sofia", { conversaAtiva: null });
        entrada.focus();
        render();
    };

    porSeletor("[data-sofia-nova]", raiz).addEventListener("click", novoChat);
    porSeletor("[data-sofia-nova-lista]", raiz).addEventListener("click", novoChat);

    porSeletor("[data-sofia-historico]", raiz).addEventListener("click", () => {
        historicoAberto = !historicoAberto;
        if (historicoAberto) {
            void hidratar();
        }
        render();
    });

    raiz.querySelectorAll<HTMLButtonElement>("[data-sofia-modo]").forEach((botao) => {
        botao.addEventListener("click", () => {
            atualizarSecao("sofia", {
                modo: botao.dataset.sofiaModo === "base" ? "base" : "search",
            });
        });
    });

    entrada.addEventListener("input", () => {
        ajustarCampo(entrada);
        enviar.disabled = enviando || entrada.value.trim() === "";
    });

    entrada.addEventListener("keydown", (evento) => {
        if (evento.key === "Enter" && !evento.shiftKey) {
            evento.preventDefault();
            porSeletor<HTMLFormElement>("[data-sofia-form]", raiz).requestSubmit();
        }
    });

    porSeletor<HTMLFormElement>("[data-sofia-form]", raiz).addEventListener("submit", (evento) => {
        evento.preventDefault();
        void enviarPergunta(raiz);
    });

    porSeletor("[data-sofia-conversas]", raiz).addEventListener("click", (evento) => {
        const alvo = evento.target;
        if (!(alvo instanceof Element)) {
            return;
        }

        const apagar = alvo.closest<HTMLElement>("[data-apagar]");
        if (apagar) {
            evento.preventDefault();
            apagarConversa(dado(apagar, "apagar"));
            return;
        }

        const abrir = alvo.closest<HTMLElement>("[data-conversa]");
        if (abrir) {
            historicoAberto = false;
            atualizarSecao("sofia", { conversaAtiva: dado(abrir, "conversa") });
            entrada.focus();
            render();
        }
    });
}

function aplicarDock(aberto: boolean): void {
    document.body.classList.toggle("sofia-dock-aberta", aberto);
    const fab = talvez<HTMLButtonElement>("[data-sofia-abrir]");
    fab?.setAttribute("aria-expanded", aberto ? "true" : "false");
    if (!aberto) {
        historicoAberto = false;
    }
}

async function hidratar(): Promise<void> {
    if (hidratou) {
        return;
    }
    hidratou = true;

    try {
        const conversas = await carregarConversasSofia();
        if (!conversas.length) {
            return;
        }

        atualizarSecao("sofia", (atual) => ({
            conversas: mesclarConversas(atual.conversas, conversas),
            conversaAtiva: atual.conversaAtiva ?? conversas[0]?.id ?? null,
        }));
    } catch {
        // Sem rede: segue com o cache do navegador.
    }
}

function mesclarConversas(
    locais: ConversaSofia[],
    servidor: ConversaSofia[],
): ConversaSofia[] {
    const mapa = new Map<string, ConversaSofia>();
    for (const conversa of [...servidor, ...locais]) {
        const atual = mapa.get(conversa.id);
        if (!atual || conversa.atualizadoEm >= atual.atualizadoEm) {
            mapa.set(conversa.id, conversa);
        }
    }

    return [...mapa.values()].sort((a, b) => b.atualizadoEm - a.atualizadoEm);
}

function conversaAberta(): ConversaSofia | null {
    const { conversaAtiva, conversas } = obterSecao("sofia");
    return conversas.find((conversa) => conversa.id === conversaAtiva) ?? null;
}

function previaDa(conversa: ConversaSofia): string {
    const ultima = [...conversa.mensagens].reverse().find((mensagem) => mensagem.texto.trim());
    if (!ultima) {
        return "Chat vazio";
    }

    const texto = ultima.texto.replace(/\s+/g, " ").trim();
    const prefixo = ultima.autor === "bot" ? "Sofia: " : "";
    const linha = `${prefixo}${texto}`;
    return linha.length > 48 ? `${linha.slice(0, 45).trimEnd()}…` : linha;
}

function render(): void {
    const raiz = talvez(`#${PAINEL_ID}`);
    if (!raiz) {
        return;
    }

    const { modo, conversaAtiva, conversas } = obterSecao("sofia");
    const aberta = conversaAberta();

    raiz.querySelectorAll<HTMLButtonElement>("[data-sofia-modo]").forEach((botao) => {
        botao.classList.toggle("active", botao.dataset.sofiaModo === modo);
    });

    porSeletor("[data-sofia-titulo]", raiz).textContent = aberta?.titulo || "Novo chat";

    const botaoHistorico = porSeletor<HTMLButtonElement>("[data-sofia-historico]", raiz);
    botaoHistorico.setAttribute("aria-expanded", historicoAberto ? "true" : "false");
    botaoHistorico.title = historicoAberto ? "Voltar ao chat" : "Ver chats";
    botaoHistorico.setAttribute("aria-label", botaoHistorico.title);

    const listaPainel = porSeletor("[data-sofia-lista]", raiz);
    const chat = porSeletor("[data-sofia-chat]", raiz);
    const form = porSeletor<HTMLFormElement>("[data-sofia-form]", raiz);

    listaPainel.hidden = !historicoAberto;
    chat.hidden = historicoAberto;
    form.hidden = historicoAberto;
    raiz.classList.toggle("mostrando-historico", historicoAberto);

    if (historicoAberto) {
        const lista = porSeletor("[data-sofia-conversas]", raiz);
        const ordem = [...conversas].sort((a, b) => b.atualizadoEm - a.atualizadoEm);

        lista.innerHTML = ordem.length
            ? ordem
                  .map((conversa) => {
                      const ativa = conversa.id === conversaAtiva ? " ativa" : "";
                      return `
              <div class="sofia-agente-conversa${ativa}">
                <button type="button" class="sofia-agente-conversa-abrir" data-conversa="${escapar(conversa.id)}">
                  <span class="sofia-agente-conversa-titulo">${escapar(conversa.titulo)}</span>
                  <span class="sofia-agente-conversa-previa">${escapar(previaDa(conversa))}</span>
                </button>
                <button
                  type="button"
                  class="icon-button sofia-agente-conversa-apagar"
                  data-apagar="${escapar(conversa.id)}"
                  aria-label="Apagar chat ${escapar(conversa.titulo)}"
                >
                  ${icone("trash-2")}
                </button>
              </div>`;
                  })
                  .join("")
            : `<p class="sofia-agente-historico-vazio">Nenhum chat ainda. Comece um novo.</p>`;

        desenharIcones(lista);
        return;
    }

    const mensagens = aberta?.mensagens ?? [];

    if (mensagens.length === 0 && !enviando) {
        chat.innerHTML = `
          <div class="sofia-agente-vazio">
            <p>Pergunte ou peça pra Sofia executar — criar empresa, agendar reunião, abrir um card.</p>
            <p class="muted">${escapar(tituloDaTela(paginaAtual))}</p>
          </div>
        `;
        return;
    }

    chat.innerHTML =
        mensagens.map(htmlMensagem).join("") +
        (enviando
            ? `<div class="sofia-agente-msg bot"><div class="sofia-agente-bolha"><span class="sofia-agente-digitando"><i></i><i></i><i></i></span></div></div>`
            : "");

    chat.scrollTop = chat.scrollHeight;
}

function htmlMensagem(mensagem: MensagemSofia): string {
    if (mensagem.autor === "user") {
        return `<div class="sofia-agente-msg user"><div class="sofia-agente-bolha">${escapar(mensagem.texto)}</div></div>`;
    }

    return `<div class="sofia-agente-msg bot"><div class="sofia-agente-bolha">${htmlDeMarkdown(mensagem.texto)}</div></div>`;
}

function tituloDaTela(id: string): string {
    const titulos: Record<string, string> = {
        inicio: "Início",
        pesquisa: "Pesquisa",
        documentos: "Documentos",
        treinamentos: "Treinamentos",
        clientes: "Empresas & clientes",
        cliente: "Detalhe do cliente",
        usuarios: "Usuários",
        comercial: "Comercial",
        reunioes: "Reuniões",
        financeiro: "Financeiro",
        projetos: "Projetos",
        processos: "Processos",
        perfil: "Perfil",
    };

    return titulos[id] ?? id;
}

function ajustarCampo(entrada: HTMLTextAreaElement): void {
    entrada.style.height = "auto";
    entrada.style.height = `${Math.min(entrada.scrollHeight, 140)}px`;
}

function apagarConversa(id: string): void {
    atualizarSecao("sofia", (atual) => {
        const conversas = atual.conversas.filter((conversa) => conversa.id !== id);
        return {
            conversas,
            conversaAtiva: atual.conversaAtiva === id ? null : atual.conversaAtiva,
        };
    });
    void apagarConversaSofia(id).catch(() => undefined);
}

async function enviarPergunta(raiz: HTMLElement): Promise<void> {
    const entrada = porSeletor<HTMLTextAreaElement>("[data-sofia-entrada]", raiz);
    const enviar = porSeletor<HTMLButtonElement>("[data-sofia-enviar]", raiz);
    const texto = entrada.value.trim();
    if (!texto || enviando || historicoAberto) {
        return;
    }

    entrada.value = "";
    ajustarCampo(entrada);
    enviar.disabled = true;
    enviando = true;

    const id = garantirConversa(texto);
    const conversa = obterSecao("sofia").conversas.find((item) => item.id === id);
    const historico = historicoDaConversa((conversa?.mensagens ?? []).slice(0, -1));
    const { modo, modelo } = obterSecao("sofia");

    render();

    let resposta = "";
    let comecou = false;

    try {
        const resultado = await perguntarSofia(texto, historico, {
            modo,
            modelo,
            conversaId: id,
            tela: paginaAtual,
            aoToken(token) {
                resposta += token;
                if (!comecou) {
                    comecou = true;
                    enviando = false;
                    anexar(id, "bot", resposta);
                    return;
                }
                substituirUltima(id, resposta);
            },
        });
        resposta = resultado.resposta;
        if (!comecou) {
            anexar(id, "bot", resposta);
        } else {
            substituirUltima(id, resposta);
        }
        sincronizarConversa(resultado.conversa);
    } catch (erro) {
        const mensagem =
            erro instanceof ErroDeApi
                ? erro.message
                : "Não consegui consultar a base agora. Tente novamente em instantes.";
        if (comecou) {
            substituirUltima(id, mensagem);
        } else {
            anexar(id, "bot", mensagem);
        }
    } finally {
        enviando = false;
        enviar.disabled = entrada.value.trim() === "";
        render();
    }
}

function garantirConversa(primeiraPergunta: string): string {
    const aberta = conversaAberta();
    if (aberta) {
        anexar(aberta.id, "user", primeiraPergunta);
        return aberta.id;
    }

    const id = crypto.randomUUID();
    const mensagem: MensagemSofia = { autor: "user", texto: primeiraPergunta };
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

function anexar(id: string, autor: MensagemSofia["autor"], texto: string): void {
    atualizarSecao("sofia", (estado) => ({
        conversas: estado.conversas.map((conversa) => {
            if (conversa.id !== id) {
                return conversa;
            }

            const mensagens = [...conversa.mensagens, { autor, texto }];
            return {
                ...conversa,
                titulo: tituloDaConversa(mensagens),
                atualizadoEm: Date.now(),
                mensagens,
            };
        }),
    }));
}

function substituirUltima(id: string, texto: string): void {
    atualizarSecao("sofia", (estado) => ({
        conversas: estado.conversas.map((conversa) => {
            if (conversa.id !== id || conversa.mensagens.length === 0) {
                return conversa;
            }

            const mensagens = [...conversa.mensagens];
            const ultima = mensagens[mensagens.length - 1];
            if (ultima?.autor !== "bot") {
                return conversa;
            }

            mensagens[mensagens.length - 1] = { ...ultima, texto };
            return { ...conversa, atualizadoEm: Date.now(), mensagens };
        }),
    }));
}

function sincronizarConversa(conversa: ConversaSofia): void {
    atualizarSecao("sofia", (estado) => {
        const demais = estado.conversas.filter((item) => item.id !== conversa.id);
        return {
            conversaAtiva: conversa.id,
            conversas: [conversa, ...demais],
        };
    });
}
