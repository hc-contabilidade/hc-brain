/* =========================================================
   SHELL DAS PÁGINAS
   Menu lateral, barra superior e modais são iguais em todas as
   telas. Em vez de repetir o markup em cada Blade, o shell monta
   tudo aqui a partir do mapa de telas que veio do servidor — então
   a barra lateral do Início é literalmente a mesma de todas as outras.
   ========================================================= */

import { APP, type SessaoUsuario } from "./bootstrap.ts";
import { dado, porSeletor, talvez, todos } from "./dom.ts";
import { escapar } from "./formato.ts";
import { desenharIcones, icone, iconeSeguro } from "./icones.ts";
import { ligarInfos, montarModais } from "./modal.ts";
import { PAGINAS, caminhoDaPagina, paginaPorId } from "./paginas.ts";
import { sair } from "./sessao.ts";
import { montarSofiaAgente } from "./sofia-agente.ts";
import { botaoDeTema, ligarTema } from "./tema.ts";

const MARCA = "HC Brain";
const SUBMARCA = "Health Care";

const TELA_PERFIL = "perfil";

function montarMenu(ativo: string, usuario: SessaoUsuario): void {
    const sidebar = talvez("[data-sidebar]");
    if (!sidebar) return;

    const numeros = APP.contadores;
    let grupoAtual: string | null = null;

    const blocos = [
        `
      <div class="logo">
        <div class="logo-container">
          <span class="logo-mark" aria-hidden="true">HC</span>
          <span class="logo-text">
            <strong>${MARCA}</strong>
            <small>${SUBMARCA}</small>
          </span>
        </div>
        <button type="button" class="icon-button" data-fechar-menu aria-label="Fechar menu">
          ${icone("x")}
        </button>
      </div>
      <nav aria-label="Navegação principal">
    `,
    ];

    for (const pagina of PAGINAS) {
        if (pagina.grupo !== grupoAtual) {
            grupoAtual = pagina.grupo;
            blocos.push(`<p class="menu-title">${escapar(grupoAtual)}</p>`);
        }

        const contador =
            pagina.contador !== undefined
                ? `<span class="nav-count">${numeros[pagina.contador]}</span>`
                : "";

        blocos.push(`
      <a class="nav-button${pagina.id === ativo ? " active" : ""}"
         href="${caminhoDaPagina(pagina.id)}"
         ${pagina.id === ativo ? 'aria-current="page"' : ""}>
        ${iconeSeguro(pagina.icone)}
        <span>${escapar(pagina.titulo)}</span>${contador}
      </a>
    `);
    }

    // O bloco do usuário é o caminho natural para o próprio perfil: quem
    // procura os próprios dados clica no próprio nome.
    const noPerfil = ativo === TELA_PERFIL;

    blocos.push(`
    </nav>
    <div class="user-section">
      <a class="user-profile${noPerfil ? " active" : ""}"
         href="${caminhoDaPagina(TELA_PERFIL)}"
         ${noPerfil ? 'aria-current="page"' : ""}>
        <span class="avatar" aria-hidden="true">${escapar(usuario.iniciais)}</span>
        <span class="user-info">
          <strong>${escapar(usuario.nome)}</strong>
          <small title="${escapar(usuario.email)}">${escapar(usuario.email)}</small>
        </span>
      </a>
      <button type="button" class="logout-button" data-sair>
        ${icone("log-out")}
        <span>Sair</span>
      </button>
    </div>
  `);

    sidebar.innerHTML = blocos.join("");
}

function montarTopbar(titulo: string, usuario: SessaoUsuario): void {
    const topbar = talvez("[data-topbar]");
    if (!topbar) return;

    topbar.innerHTML = `
    <div class="topbar-left">
      <button type="button" class="menu-toggle" data-abrir-menu
              aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
        ${icone("menu")}
      </button>
      <span class="topbar-brand">
        <span class="topbar-mark" aria-hidden="true">HC</span>
        ${escapar(titulo)}
      </span>
    </div>

    <div class="topbar-right">
      ${botaoDeTema()}
      <span class="topbar-user">
        <span class="avatar" aria-hidden="true">${escapar(usuario.iniciais)}</span>
        <span>${escapar(usuario.nome)}</span>
      </span>
    </div>
  `;
}

/**
 * Abrir e fechar a gaveta: overlay, rolagem travada e conteúdo inerte
 * enquanto o menu está por cima da página.
 */
function ligarGaveta(app: HTMLElement): void {
    const main = talvez(".main", app);
    const barra = talvez("[data-sidebar]", app);
    const gatilho = talvez("[data-abrir-menu]", app);
    const fechar = talvez("[data-fechar-menu]", app);

    const overlay = document.createElement("div");
    overlay.className = "sidebar-overlay";
    overlay.setAttribute("aria-hidden", "true");
    app.prepend(overlay);

    function definir(aberta: boolean, moverFoco = true): void {
        app.classList.toggle("sidebar-collapsed", !aberta);
        document.documentElement.classList.toggle("no-scroll", aberta);
        gatilho?.setAttribute("aria-expanded", String(aberta));
        gatilho?.setAttribute("aria-label", aberta ? "Fechar menu" : "Abrir menu");
        if (main) main.inert = aberta;
        if (!moverFoco) {
            return;
        }

        // Com a gaveta aberta o foco vai para dentro dela. Ao fechar ele
        // precisa sair: a barra some da árvore de foco, e quem fechou pelo X
        // ficaria com o cursor num elemento invisível.
        if (aberta) {
            fechar?.focus();

            return;
        }

        const foco = document.activeElement;
        if (foco === document.body || (foco instanceof Node && barra?.contains(foco))) {
            gatilho?.focus();
        }
    }

    gatilho?.addEventListener("click", () =>
        definir(app.classList.contains("sidebar-collapsed")),
    );
    fechar?.addEventListener("click", () => definir(false));
    overlay.addEventListener("click", () => definir(false));
    document.addEventListener("keydown", (evento) => {
        if (evento.key === "Escape") definir(false);
    });

    // O HTML já nasce com a gaveta fechada; aqui só alinhamos ARIA e rolagem
    // ao que a tela mostra. Sem mexer no foco: abrir a página não é o mesmo
    // que fechar o menu, e mandar o cursor para o botão de menu a cada carga
    // tirava o foco de quem ia direto ao conteúdo.
    definir(false, false);
}

/**
 * Monta o shell da página e devolve o usuário logado.
 * `paginaId` é o id da tela atual (ver app/Support/Telas.php).
 */
export function iniciarPagina(paginaId: string): SessaoUsuario {
    const usuario = APP.usuario;
    const pagina = paginaPorId(paginaId);
    const app = porSeletor(".app");
    const sidebar = talvez("[data-sidebar]", app);

    if (sidebar) sidebar.id = "sidebar";

    montarMenu(pagina?.menu ?? paginaId, usuario);
    montarTopbar(pagina?.titulo ?? MARCA, usuario);
    montarModais();
    ligarGaveta(app);
    ligarTema();
    desenharIcones();

    todos("[data-sair]").forEach((botao) => {
        botao.addEventListener("click", sair);
    });

    // Atalhos e itens de lista que apontam para outra tela.
    todos("[data-page]").forEach((elemento) => {
        elemento.addEventListener("click", () => {
            window.location.href = caminhoDaPagina(dado(elemento, "page"));
        });
    });

    ligarInfos();
    montarSofiaAgente(paginaId);

    return usuario;
}
