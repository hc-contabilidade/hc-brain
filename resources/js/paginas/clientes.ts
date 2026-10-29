/* =========================================================
   TELA EMPRESAS & CLIENTES
   Lista o banco central. Cada empresa abre em uma página própria
   (/clientes/{id}), mantendo busca e modo de exibição salvos para
   quando você voltar. A edição da ficha (nome, status, sócio) abre
   no formulário compartilhado.

   A carteira tem mais de mil empresas, então só a visão ativa é
   montada, e em lotes: o próximo lote entra quando o fim da lista
   chega perto da tela. A busca continua valendo sobre todas.
   ========================================================= */

import { carregarEmpresas, type Empresa } from "../dados/empresas.ts";
import { combinaPreparado, termoDeBusca, textoDeBusca } from "../comum/busca.ts";
import { alvoMaisProximo, campo, dado, porId, todos } from "../comum/dom.ts";
import {
    atualizarSecao,
    observarEstado,
    obterSecao,
    type VisualizacaoLista,
} from "../comum/estado.ts";
import { abrirFormularioDeEmpresa } from "../comum/formulario-empresa.ts";
import { escapar, iniciais, numero } from "../comum/formato.ts";
import { desenharIcones, icone } from "../comum/icones.ts";
import { caminhoDaPagina } from "../comum/paginas.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("clientes");

const lista = porId("empresasList");
const grid = porId("empresasGrid");
const mais = porId("empresasMais");
const busca = campo("empresaSearch");

/** Quantas empresas entram na tela de cada vez. */
const LOTE = 60;

type LinhaEmpresa = { empresa: Empresa; texto: string };

let empresas: LinhaEmpresa[] = [];
let filtradas: Empresa[] = [];
let exibidas = 0;
let buscaAplicada: string | null = null;

async function carregarLista(): Promise<void> {
    empresas = (await carregarEmpresas()).map((empresa) => ({
        empresa,
        texto: textoDeBusca(
            `${empresa.nome} ${empresa.setor} ${empresa.socio.nome} ${empresa.status}`,
        ),
    }));
}

await carregarLista();

function empresasFiltradas(): Empresa[] {
    const palavras = termoDeBusca(obterSecao("empresas").busca);
    return empresas
        .filter(({ texto }) => combinaPreparado(texto, palavras))
        .map(({ empresa }) => empresa);
}

function renderEmpresaCard(empresa: Empresa): string {
    const detalhe = caminhoDaPagina("cliente", { id: empresa.id });

    return `
    <article class="empresa-card">
      <div class="empresa-card-top">
        <span class="empresa-avatar">${iniciais(empresa.nome)}</span>
        <div class="empresa-card-acoes">
          <span class="tag ${empresa.statusTag}">${escapar(empresa.status)}</span>
          <button
            type="button"
            class="icon-button"
            data-editar="${escapar(empresa.id)}"
            aria-label="Editar ${escapar(empresa.nome)}"
            title="Editar"
          >${icone("pencil")}</button>
        </div>
      </div>

      <a class="empresa-card-link" href="${detalhe}">
        <h3>${escapar(empresa.nome)}</h3>
        <span class="empresa-setor">${escapar(empresa.setor)}</span>

        <div class="empresa-socio-line">
          <strong>${escapar(empresa.socio.nome)}</strong>
          ${escapar(empresa.socio.cargo)} • sócio responsável
        </div>

        <dl class="empresa-stats-row">
          <div><dt>Reuniões</dt><dd>${empresa.totalReunioes}</dd></div>
          <div><dt>Fontes</dt><dd>${empresa.totalFontes}</dd></div>
          <div><dt>Última</dt><dd>${escapar(empresa.ultimaReuniao?.tipo ?? "—")}</dd></div>
        </dl>
      </a>
    </article>
  `;
}

function renderEmpresaListItem(empresa: Empresa): string {
    const detalhe = caminhoDaPagina("cliente", { id: empresa.id });

    return `
    <div class="empresa-list-item">
      <a class="empresa-list-link" href="${detalhe}">
        <span class="empresa-avatar empresa-list-avatar">${iniciais(empresa.nome)}</span>
        <span class="empresa-list-main">
          <span class="empresa-list-name">
            <strong>${escapar(empresa.nome)}</strong>
            <span class="tag ${empresa.statusTag}">${escapar(empresa.status)}</span>
          </span>
          <span class="empresa-list-sub">${escapar(empresa.setor)} • ${escapar(empresa.socio.nome)} — ${escapar(empresa.socio.cargo)}</span>
        </span>
        <span class="empresa-list-info">
          <strong>${empresa.totalReunioes}</strong>
          ${empresa.totalReunioes === 1 ? "reunião" : "reuniões"}
        </span>
        <span class="empresa-list-info hide-mobile">
          <strong>${empresa.totalFontes}</strong>
          documentos e fontes
        </span>
      </a>
      <button
        type="button"
        class="icon-button empresa-list-editar"
        data-editar="${escapar(empresa.id)}"
        aria-label="Editar ${escapar(empresa.nome)}"
        title="Editar"
      >${icone("pencil")}</button>
      <a class="empresa-list-action" href="${detalhe}" aria-label="Abrir ${escapar(empresa.nome)}">${icone("chevron-right")}</a>
    </div>
  `;
}

function visaoAtiva(): { alvo: HTMLElement; render: (empresa: Empresa) => string } {
    return obterSecao("empresas").visualizacao === "cards"
        ? { alvo: grid, render: renderEmpresaCard }
        : { alvo: lista, render: renderEmpresaListItem };
}

function renderContador(): void {
    const restantes = filtradas.length - exibidas;

    mais.hidden = filtradas.length === 0;
    mais.innerHTML = `
    <span>Mostrando ${numero(exibidas)} de ${numero(filtradas.length)} empresas</span>
    ${restantes > 0 ? `<button class="secondary" type="button" data-mostrar-mais>Mostrar mais ${numero(Math.min(restantes, LOTE))}</button>` : ""}
  `;
}

/** Acrescenta o próximo lote ao fim da visão ativa, sem redesenhar o que já está na tela. */
function mostrarMais(): void {
    if (exibidas >= filtradas.length) {
        return;
    }

    const { alvo, render } = visaoAtiva();
    const lote = filtradas.slice(exibidas, exibidas + LOTE);

    alvo.insertAdjacentHTML("beforeend", lote.map(render).join(""));
    exibidas += lote.length;

    renderContador();
    desenharIcones(alvo);
}

function renderEmpresas(): void {
    const estado = obterSecao("empresas");
    const { alvo } = visaoAtiva();
    const vazio = `<div class="empty-state">Nenhuma empresa encontrada.</div>`;

    // Outra aba mexendo em outra seção também chama aqui; sem mudança na
    // busca, a lista filtrada é a mesma e não precisa ser refeita.
    if (estado.busca !== buscaAplicada) {
        filtradas = empresasFiltradas();
        buscaAplicada = estado.busca;
    }

    grid.innerHTML = "";
    lista.innerHTML = "";
    exibidas = 0;

    if (filtradas.length) {
        mostrarMais();
    } else {
        alvo.innerHTML = vazio;
        renderContador();
    }

    grid.classList.toggle("hidden-view", estado.visualizacao !== "cards");
    lista.classList.toggle("hidden-view", estado.visualizacao !== "list");

    todos("[data-empresa-view]").forEach((botao) => {
        botao.classList.toggle(
            "active",
            dado(botao, "empresaView") === estado.visualizacao,
        );
    });

    if (busca.value !== estado.busca) busca.value = estado.busca;
}

function abrirEdicao(id: string): void {
    const linha = empresas.find((registro) => registro.empresa.id === id);
    if (!linha) {
        return;
    }

    void abrirFormularioDeEmpresa(linha.empresa, {
        async aoSalvar() {
            await carregarLista();
            buscaAplicada = null;
            renderEmpresas();
        },
    });
}

function aoClicarEditar(evento: Event): void {
    const botao = alvoMaisProximo(evento, "[data-editar]");
    if (!botao) {
        return;
    }

    evento.preventDefault();
    evento.stopPropagation();
    abrirEdicao(dado(botao, "editar"));
}

busca.addEventListener("input", () => atualizarSecao("empresas", { busca: busca.value }));

mais.addEventListener("click", (evento) => {
    if (alvoMaisProximo(evento, "[data-mostrar-mais]")) {
        mostrarMais();
    }
});

lista.addEventListener("click", aoClicarEditar);
grid.addEventListener("click", aoClicarEditar);

// O próximo lote entra sozinho quando o contador chega perto da tela; o botão
// fica para quem navega pelo teclado ou quando o observador não dispara.
new IntersectionObserver(
    (entradas) => {
        if (entradas.some((entrada) => entrada.isIntersecting)) {
            mostrarMais();
        }
    },
    { rootMargin: "400px" },
).observe(mais);

porId("empresaViewToggle").addEventListener("click", (evento) => {
    const botao = alvoMaisProximo(evento, "[data-empresa-view]");
    if (botao) {
        atualizarSecao("empresas", {
            visualizacao: dado(botao, "empresaView") as VisualizacaoLista,
        });
    }
});

observarEstado(["empresas"], renderEmpresas);
renderEmpresas();
