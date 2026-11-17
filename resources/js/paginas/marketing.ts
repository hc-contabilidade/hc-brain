/* =========================================================
   TELA MARKETING — leitura densa, visual limpo
   ========================================================= */

import {
    carregarMarketing,
    carregarPeriodosMarketing,
    type ConteudoDestaque,
    type IdeiaVideo,
    type PontoSerie,
    type ResumoMarketing,
} from "../dados/marketing.ts";
import { porId, selecao } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar } from "../comum/formato.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("marketing");

const filtroPeriodo = selecao("marketingMonthFilter");

const periodos = await carregarPeriodosMarketing();

filtroPeriodo.innerHTML = periodos
    .map(({ id, rotulo }) => `<option value="${id}">${rotulo}</option>`)
    .join("");
filtroPeriodo.disabled = periodos.length === 0;

function formatarNumero(valor: number): string {
    return new Intl.NumberFormat("pt-BR").format(valor);
}

function formatarCurto(valor: number): string {
    if (Math.abs(valor) >= 1000) {
        return new Intl.NumberFormat("pt-BR", {
            notation: "compact",
            maximumFractionDigits: 1,
        }).format(valor);
    }
    return formatarNumero(valor);
}

function formatarDelta(valor: number): string {
    if (valor === 0) {
        return "0";
    }
    return `${valor > 0 ? "+" : ""}${formatarNumero(valor)}`;
}

function classeDelta(valor: number): string {
    if (valor > 0) {
        return "up";
    }
    if (valor < 0) {
        return "down";
    }
    return "neutral";
}

function rotuloPlataforma(valor: IdeiaVideo["plataforma"]): string {
    return { youtube: "YT", instagram: "IG", ambos: "YT+IG" }[valor];
}

function rotuloStatus(valor: IdeiaVideo["status"]): string {
    return {
        rascunho: "Rascunho",
        aprovada: "Aprovada",
        publicada: "Publicada",
        arquivada: "Arquivada",
    }[valor];
}

function celula(rotulo: string, valor: string, delta?: number): string {
    const deltaHtml =
        delta === undefined
            ? ""
            : `<em class="marketing-delta ${classeDelta(delta)}">${formatarDelta(delta)}</em>`;

    return `<div class="marketing-cell">
      <span>${escapar(rotulo)}</span>
      <strong>${valor}</strong>
      ${deltaHtml}
    </div>`;
}

function renderSerie(
    alvo: string,
    serie: PontoSerie[],
    opcoes: { ganhoLabel: string; audienciaLabel: string },
): void {
    const maxViews = Math.max(...serie.map((ponto) => ponto.views), 1);
    const maxGanho = Math.max(...serie.map((ponto) => ponto.ganho), 1);

    porId(alvo).innerHTML = serie.length
        ? `<div class="marketing-chart">
            <div class="marketing-chart-legend">
              <span><i class="marketing-swatch marketing-swatch-views"></i>Views</span>
              <span><i class="marketing-swatch marketing-swatch-ganho"></i>${escapar(opcoes.ganhoLabel)}</span>
            </div>
            <div class="marketing-bars">${serie
                .map((ponto) => {
                    const pico = ponto.ganho === maxGanho && ponto.ganho > 0;
                    return `
              <div class="marketing-month-col${pico ? " is-peak" : ""}" title="${escapar(ponto.rotulo)} · views ${formatarNumero(ponto.views)} · ${escapar(opcoes.ganhoLabel)} ${formatarNumero(ponto.ganho)} · ${escapar(opcoes.audienciaLabel)} ${formatarNumero(ponto.audiencia)}">
                <div class="marketing-month-bar-wrap">
                  <div class="marketing-month-pair">
                    <div class="marketing-month-bar marketing-month-bar-views" style="height:${Math.max(4, (ponto.views / maxViews) * 100)}%"></div>
                    <div class="marketing-month-bar marketing-month-bar-ganho" style="height:${Math.max(4, (ponto.ganho / maxGanho) * 100)}%"></div>
                  </div>
                </div>
                <span class="marketing-month-label">${escapar(ponto.rotulo)}</span>
                ${pico ? `<span class="marketing-month-peak">${formatarCurto(ponto.ganho)}</span>` : ""}
              </div>`;
                })
                .join("")}</div>
          </div>`
        : `<div class="empty-state">Sem série neste período.</div>`;
}

function renderInsight(
    alvo: string,
    destaque: ConteudoDestaque | null,
    tipo: "youtube" | "instagram",
    variacao: number,
): void {
    if (!destaque) {
        porId(alvo).innerHTML = "";
        return;
    }

    const ganho =
        tipo === "youtube"
            ? `+${formatarNumero(destaque.ganhoInscritos)} inscritos`
            : `+${formatarNumero(destaque.ganhoSeguidores)} seguidores`;

    const nota =
        tipo === "youtube" && variacao > 0
            ? ` · salto ${formatarDelta(variacao)} no mês`
            : "";

    const href = destaque.url ?? "#";

    porId(alvo).innerHTML = `
      <a class="marketing-insight-row" href="${escapar(href)}" target="_blank" rel="noopener noreferrer">
        <span class="marketing-insight-tag">Destaque</span>
        <span class="marketing-insight-title">${escapar(destaque.titulo)}</span>
        <span class="marketing-insight-nums">
          ${formatarCurto(destaque.views)} views · ${formatarCurto(destaque.likes)} likes · ${ganho}${nota}
        </span>
      </a>`;
}

function renderIdeias(ideias: IdeiaVideo[]): void {
    porId("marketingIdeasCount").textContent = ideias.length
        ? `${ideias.length} na fila`
        : "";

    porId("marketingIdeas").innerHTML = ideias.length
        ? `<div class="marketing-ideas-table">
            <div class="marketing-ideas-head">
              <span>Ideia</span>
              <span>Canal</span>
              <span>Resp.</span>
              <span>Status</span>
            </div>
            ${ideias
                .map(
                    (ideia) => `
              <div class="marketing-idea-row">
                <div class="marketing-idea-main">
                  <strong>${escapar(ideia.titulo)}</strong>
                  <span>${escapar(ideia.descricao || "—")}</span>
                </div>
                <span>${rotuloPlataforma(ideia.plataforma)}</span>
                <span>${escapar(ideia.responsavel || "—")}</span>
                <span class="marketing-idea-status">${rotuloStatus(ideia.status)}</span>
              </div>`,
                )
                .join("")}
          </div>`
        : `<div class="empty-state">Nenhuma ideia na fila.</div>`;
}

function renderMarketing(dados: ResumoMarketing): void {
    porId("marketingCanais").innerHTML = `
      <a class="marketing-canal-chip" href="${escapar(dados.canais.youtube)}" target="_blank" rel="noopener noreferrer">YouTube</a>
      <a class="marketing-canal-chip" href="${escapar(dados.canais.instagram)}" target="_blank" rel="noopener noreferrer">Instagram</a>
    `;

    (porId("marketingLinkYoutube") as HTMLAnchorElement).href = dados.canais.youtube;
    (porId("marketingLinkInstagram") as HTMLAnchorElement).href = dados.canais.instagram;

    porId("marketingSnapshot").innerHTML = `
      <section class="marketing-snap-group">
        <header>YouTube</header>
        <div class="marketing-snap-grid">
          ${celula("Views", formatarCurto(dados.youtube.views), dados.youtube.variacaoViews)}
          ${celula("Likes", formatarCurto(dados.youtube.likes))}
          ${celula("Coment.", formatarCurto(dados.youtube.comentarios))}
          ${celula("Shares", formatarCurto(dados.youtube.compartilhamentos))}
          ${celula("Novos", formatarCurto(dados.youtube.inscritosNovos), dados.youtube.variacaoInscritos)}
          ${celula("Total", formatarCurto(dados.youtube.inscritosTotal))}
        </div>
      </section>
      <section class="marketing-snap-group">
        <header>Instagram</header>
        <div class="marketing-snap-grid">
          ${celula("Views", formatarCurto(dados.instagram.views), dados.instagram.variacaoViews)}
          ${celula("Likes", formatarCurto(dados.instagram.likes))}
          ${celula("Coment.", formatarCurto(dados.instagram.comentarios))}
          ${celula("Shares", formatarCurto(dados.instagram.compartilhamentos))}
          ${celula("Alcance", formatarCurto(dados.instagram.alcance), dados.instagram.variacaoAlcance)}
          ${celula("Seguidores", formatarCurto(dados.instagram.seguidores), dados.instagram.variacaoSeguidores)}
        </div>
      </section>
    `;

    porId("marketingYoutubeStats").innerHTML = `
      <span>Engajamento ${(
          (dados.youtube.likes + dados.youtube.comentarios) /
          Math.max(dados.youtube.views, 1)
      ).toLocaleString("pt-BR", { style: "percent", maximumFractionDigits: 1 })}</span>
      <span>Inscritos ${formatarNumero(dados.youtube.inscritosTotal)}</span>
      <span class="marketing-delta ${classeDelta(dados.youtube.variacaoInscritos)}">${formatarDelta(dados.youtube.variacaoInscritos)} novos vs mês ant.</span>
    `;

    porId("marketingInstagramStats").innerHTML = `
      <span>Engajamento ${(
          (dados.instagram.likes + dados.instagram.comentarios) /
          Math.max(dados.instagram.views, 1)
      ).toLocaleString("pt-BR", { style: "percent", maximumFractionDigits: 1 })}</span>
      <span>Seguidores ${formatarNumero(dados.instagram.seguidores)}</span>
      <span class="marketing-delta ${classeDelta(dados.instagram.variacaoSeguidores)}">${formatarDelta(dados.instagram.variacaoSeguidores)} vs mês ant.</span>
    `;

    renderSerie("marketingYoutubeChart", dados.serieYoutube, {
        ganhoLabel: "Inscritos novos",
        audienciaLabel: "Inscritos totais",
    });
    renderSerie("marketingInstagramChart", dados.serieInstagram, {
        ganhoLabel: "Alcance",
        audienciaLabel: "Seguidores",
    });
    renderInsight(
        "marketingYoutubeInsight",
        dados.destaqueYoutube,
        "youtube",
        dados.youtube.variacaoInscritos,
    );
    renderInsight(
        "marketingInstagramInsight",
        dados.destaqueInstagram,
        "instagram",
        dados.instagram.variacaoSeguidores,
    );
    renderIdeias(dados.ideias);
    filtroPeriodo.value = dados.periodo;
}

async function carregarEExibir(): Promise<void> {
    if (!periodos.length) {
        porId("marketingSnapshot").innerHTML =
            `<div class="empty-state">Nenhuma métrica de marketing na base ainda.</div>`;
        porId("marketingYoutubeChart").innerHTML = "";
        porId("marketingInstagramChart").innerHTML = "";
        porId("marketingYoutubeInsight").innerHTML = "";
        porId("marketingInstagramInsight").innerHTML = "";
        porId("marketingYoutubeStats").innerHTML = "";
        porId("marketingInstagramStats").innerHTML = "";
        porId("marketingIdeas").innerHTML = "";
        porId("marketingIdeasCount").textContent = "";
        return;
    }

    renderMarketing(await carregarMarketing(obterSecao("marketing").periodo));
}

filtroPeriodo.addEventListener("change", () =>
    atualizarSecao("marketing", { periodo: filtroPeriodo.value }),
);

observarEstado(["marketing"], () => {
    void carregarEExibir();
});

await carregarEExibir();
