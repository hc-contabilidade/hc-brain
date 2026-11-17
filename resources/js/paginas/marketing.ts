/* =========================================================
   TELA MARKETING (rascunho)
   Ideias + KPIs/gráficos YouTube e Instagram — só leitura.
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

function formatarDelta(valor: number, sufixo = ""): string {
    if (valor === 0) {
        return `estável${sufixo}`;
    }
    const sinal = valor > 0 ? "+" : "";
    return `${sinal}${formatarNumero(valor)}${sufixo}`;
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
    return { youtube: "YouTube", instagram: "Instagram", ambos: "YouTube + Instagram" }[valor];
}

function rotuloStatus(valor: IdeiaVideo["status"]): string {
    return {
        rascunho: "Rascunho",
        aprovada: "Aprovada",
        publicada: "Publicada",
        arquivada: "Arquivada",
    }[valor];
}

function renderSerie(
    alvo: string,
    serie: PontoSerie[],
    opcoes: { ganhoLabel: string; tom: "yt" | "ig" },
): void {
    const maxViews = Math.max(...serie.map((ponto) => ponto.views), 1);
    const maxGanho = Math.max(...serie.map((ponto) => ponto.ganho), 1);

    porId(alvo).innerHTML = serie.length
        ? `<div class="marketing-chart" data-tom="${opcoes.tom}">
            <div class="marketing-chart-legend">
              <span><i class="marketing-swatch marketing-swatch-views"></i> Views</span>
              <span><i class="marketing-swatch marketing-swatch-ganho"></i> ${escapar(opcoes.ganhoLabel)}</span>
            </div>
            <div class="marketing-bars">${serie
                .map((ponto) => {
                    const salto = ponto.ganho === maxGanho && ponto.ganho > 0;
                    return `
              <div class="marketing-month-col${salto ? " is-peak" : ""}">
                <span class="marketing-month-value">${formatarNumero(ponto.views)}</span>
                <div class="marketing-month-bar-wrap">
                  <div class="marketing-month-pair">
                    <div class="marketing-month-bar marketing-month-bar-views" style="height:${Math.max(6, (ponto.views / maxViews) * 100)}%"></div>
                    <div class="marketing-month-bar marketing-month-bar-ganho" style="height:${Math.max(6, (ponto.ganho / maxGanho) * 100)}%" title="${escapar(opcoes.ganhoLabel)}: ${formatarNumero(ponto.ganho)}"></div>
                  </div>
                </div>
                <span class="marketing-month-ganho">${formatarNumero(ponto.ganho)}</span>
                <span class="marketing-month-label">${escapar(ponto.rotulo)}</span>
              </div>`;
                })
                .join("")}</div>
          </div>`
        : `<div class="empty-state">Sem métricas neste período.</div>`;
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
            ? `${formatarNumero(destaque.ganhoInscritos)} inscritos novos`
            : `${formatarNumero(destaque.ganhoSeguidores)} seguidores novos`;

    const salto =
        tipo === "youtube" && variacao >= 80
            ? `<p class="marketing-insight-note">Salto de <strong>${formatarDelta(variacao)}</strong> inscritos no mês vs o anterior — este vídeo concentrou o crescimento.</p>`
            : tipo === "instagram" && destaque.ganhoSeguidores > 0
              ? `<p class="marketing-insight-note">Conteúdo que mais ajudou a base de seguidores no período.</p>`
              : "";

    const url = destaque.url
        ? `<a class="marketing-insight-link" href="${escapar(destaque.url)}" target="_blank" rel="noopener noreferrer">Ver no canal</a>`
        : "";

    porId(alvo).innerHTML = `
      <div class="marketing-insight-card">
        <div class="marketing-insight-eyebrow">${tipo === "youtube" ? "Vídeo em destaque" : "Conteúdo em destaque"}</div>
        <strong>${escapar(destaque.titulo)}</strong>
        <div class="marketing-insight-metrics">
          <span>${formatarNumero(destaque.views)} views</span>
          <span>${formatarNumero(destaque.likes)} likes</span>
          <span>${ganho}</span>
        </div>
        ${salto}
        ${url}
      </div>`;
}

function renderIdeias(ideias: IdeiaVideo[]): void {
    porId("marketingIdeas").innerHTML = ideias.length
        ? ideias
              .map(
                  (ideia) => `
        <div class="marketing-idea-row">
          <div class="marketing-idea-main">
            <strong>${escapar(ideia.titulo)}</strong>
            <span>${escapar(ideia.descricao || "Sem descrição")} · ${rotuloPlataforma(ideia.plataforma)}</span>
          </div>
          <div class="marketing-idea-meta">
            <span class="marketing-idea-status">${rotuloStatus(ideia.status)}</span>
            <div>${escapar(ideia.responsavel || "—")} · ${ideia.prioridade}</div>
          </div>
        </div>`,
              )
              .join("")
        : `<div class="empty-state">Nenhuma ideia em rascunho ou aprovada.</div>`;
}

function renderMarketing(dados: ResumoMarketing): void {
    porId("marketingCanais").innerHTML = `
      <a class="marketing-canal-chip" href="${escapar(dados.canais.youtube)}" target="_blank" rel="noopener noreferrer">YouTube</a>
      <a class="marketing-canal-chip" href="${escapar(dados.canais.instagram)}" target="_blank" rel="noopener noreferrer">Instagram</a>
    `;

    const linkYt = porId("marketingLinkYoutube") as HTMLAnchorElement;
    const linkIg = porId("marketingLinkInstagram") as HTMLAnchorElement;
    linkYt.href = dados.canais.youtube;
    linkIg.href = dados.canais.instagram;

    porId("marketingKpis").innerHTML = `
    <div class="marketing-kpi">
      <span class="marketing-kpi-label">YT · views</span>
      <strong class="marketing-kpi-value">${formatarNumero(dados.youtube.views)}</strong>
      <div class="marketing-kpi-foot"><span class="marketing-delta ${classeDelta(dados.youtube.variacaoViews)}">${formatarDelta(dados.youtube.variacaoViews)}</span> vs mês ant.</div>
    </div>
    <div class="marketing-kpi marketing-kpi-emphasis">
      <span class="marketing-kpi-label">YT · inscritos novos</span>
      <strong class="marketing-kpi-value">${formatarNumero(dados.youtube.inscritosNovos)}</strong>
      <div class="marketing-kpi-foot"><span class="marketing-delta ${classeDelta(dados.youtube.variacaoInscritos)}">${formatarDelta(dados.youtube.variacaoInscritos)}</span> · total ${formatarNumero(dados.youtube.inscritosTotal)}</div>
    </div>
    <div class="marketing-kpi">
      <span class="marketing-kpi-label">IG · views</span>
      <strong class="marketing-kpi-value">${formatarNumero(dados.instagram.views)}</strong>
      <div class="marketing-kpi-foot"><span class="marketing-delta ${classeDelta(dados.instagram.variacaoViews)}">${formatarDelta(dados.instagram.variacaoViews)}</span> vs mês ant.</div>
    </div>
    <div class="marketing-kpi marketing-kpi-emphasis">
      <span class="marketing-kpi-label">IG · seguidores</span>
      <strong class="marketing-kpi-value">${formatarNumero(dados.instagram.seguidores)}</strong>
      <div class="marketing-kpi-foot"><span class="marketing-delta ${classeDelta(dados.instagram.variacaoSeguidores)}">${formatarDelta(dados.instagram.variacaoSeguidores)}</span> · alcance ${formatarNumero(dados.instagram.alcance)}</div>
    </div>
  `;

    renderSerie("marketingYoutubeChart", dados.serieYoutube, {
        ganhoLabel: "Inscritos novos",
        tom: "yt",
    });
    renderSerie("marketingInstagramChart", dados.serieInstagram, {
        ganhoLabel: "Alcance",
        tom: "ig",
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
        porId("marketingKpis").innerHTML =
            `<div class="empty-state">Nenhuma métrica de marketing na base ainda.</div>`;
        porId("marketingYoutubeChart").innerHTML = "";
        porId("marketingInstagramChart").innerHTML = "";
        porId("marketingYoutubeInsight").innerHTML = "";
        porId("marketingInstagramInsight").innerHTML = "";
        porId("marketingIdeas").innerHTML = "";
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
