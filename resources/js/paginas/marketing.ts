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
    type VideoRanking,
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

function renderSerieViews(alvo: string, serie: PontoSerie[], ganhoLabel: string): void {
    const maxViews = Math.max(...serie.map((ponto) => ponto.views), 1);

    porId(alvo).innerHTML = serie.some((ponto) => ponto.views > 0)
        ? `<div class="marketing-chart">
            <div class="marketing-bars">${serie
                .map((ponto) => {
                    const pico = ponto.views === maxViews && ponto.views > 0;
                    return `
              <div class="marketing-month-col${pico ? " is-peak" : ""}" title="${escapar(ponto.rotulo)} · ${formatarNumero(ponto.views)} views · ${escapar(ganhoLabel)} ${formatarNumero(ponto.ganho)}">
                <div class="marketing-month-bar-wrap">
                  <div class="marketing-month-bar marketing-month-bar-views" style="height:${Math.max(4, (ponto.views / maxViews) * 100)}%"></div>
                </div>
                <span class="marketing-month-label">${escapar(ponto.rotulo)}</span>
                ${pico ? `<span class="marketing-month-peak">${formatarCurto(ponto.views)}</span>` : ""}
              </div>`;
                })
                .join("")}</div>
          </div>`
        : `<div class="empty-state">Sem views neste recorte.</div>`;
}

function renderSerieSaldo(alvo: string, serie: PontoSerie[]): void {
    const maxAbs = Math.max(...serie.map((ponto) => Math.abs(ponto.saldo)), 1);
    const temDados = serie.some((ponto) => ponto.audiencia > 0 || ponto.saldo !== 0);

    porId(alvo).innerHTML = temDados
        ? `<div class="marketing-chart marketing-chart-saldo">
            <div class="marketing-saldo-note">Saldo líquido entre snapshots do canal (não é unfollow oficial da API).</div>
            <div class="marketing-bars marketing-bars-saldo">${serie
                .map((ponto) => {
                    const altura = Math.max(4, (Math.abs(ponto.saldo) / maxAbs) * 100);
                    const sentido = ponto.saldo >= 0 ? "up" : "down";
                    return `
              <div class="marketing-month-col" title="${escapar(ponto.rotulo)} · saldo ${formatarDelta(ponto.saldo)} · total ${formatarNumero(ponto.audiencia)}">
                <span class="marketing-month-saldo marketing-delta ${classeDelta(ponto.saldo)}">${formatarDelta(ponto.saldo)}</span>
                <div class="marketing-saldo-track">
                  <div class="marketing-saldo-zero"></div>
                  <div class="marketing-saldo-bar ${sentido}" style="height:${altura}%"></div>
                </div>
                <span class="marketing-month-label">${escapar(ponto.rotulo)}</span>
                <span class="marketing-month-audiencia">${ponto.audiencia > 0 ? formatarCurto(ponto.audiencia) : "—"}</span>
              </div>`;
                })
                .join("")}</div>
          </div>`
        : `<div class="empty-state">Ainda sem histórico de inscritos. Rode a sync em meses seguintes para ver o saldo.</div>`;
}

function renderVideos(alvo: string, videos: VideoRanking[]): void {
    if (!videos.length) {
        porId(alvo).innerHTML = "";
        return;
    }

    const maxViews = Math.max(...videos.map((video) => video.views), 1);

    porId(alvo).innerHTML = `
      <div class="marketing-videos-head">Ranking de vídeos</div>
      <div class="marketing-videos-list">
        ${videos
            .map((video, indice) => {
                const href = video.url ?? "#";
                return `
          <a class="marketing-video-row" href="${escapar(href)}" target="_blank" rel="noopener noreferrer">
            <span class="marketing-video-rank">${indice + 1}</span>
            <span class="marketing-video-main">
              <strong>${escapar(video.titulo)}</strong>
              <span>${formatarCurto(video.views)} views · ${formatarCurto(video.likes)} likes · ${formatarCurto(video.comentarios)} coment.</span>
            </span>
            <span class="marketing-video-bar-wrap" aria-hidden="true">
              <span class="marketing-video-bar" style="width:${Math.max(8, (video.views / maxViews) * 100)}%"></span>
            </span>
          </a>`;
            })
            .join("")}
      </div>`;
}

function renderInsight(
    alvo: string,
    destaque: ConteudoDestaque | null,
    tipo: "youtube" | "instagram",
    saldo: number,
): void {
    if (!destaque) {
        porId(alvo).innerHTML = "";
        return;
    }

    const nota =
        tipo === "youtube"
            ? ` · saldo do canal ${formatarDelta(saldo)}`
            : "";

    const href = destaque.url ?? "#";

    porId(alvo).innerHTML = `
      <a class="marketing-insight-row" href="${escapar(href)}" target="_blank" rel="noopener noreferrer">
        <span class="marketing-insight-tag">Destaque</span>
        <span class="marketing-insight-title">${escapar(destaque.titulo)}</span>
        <span class="marketing-insight-nums">
          ${formatarCurto(destaque.views)} views · ${formatarCurto(destaque.likes)} likes${nota}
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
        <header>YouTube · visibilidade</header>
        <div class="marketing-snap-grid">
          ${celula("Views", formatarCurto(dados.youtube.views), dados.youtube.variacaoViews)}
          ${celula("Likes", formatarCurto(dados.youtube.likes))}
          ${celula("Coment.", formatarCurto(dados.youtube.comentarios))}
          ${celula("Engaj.", `${(dados.youtube.engajamento * 100).toFixed(1).replace(".", ",")}%`)}
          ${celula("Saldo", formatarDelta(dados.youtube.saldoInscritos), dados.youtube.saldoInscritos)}
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
      <span>Inscritos ${formatarNumero(dados.youtube.inscritosTotal)}</span>
      <span class="marketing-delta ${classeDelta(dados.youtube.saldoInscritos)}">saldo ${formatarDelta(dados.youtube.saldoInscritos)}</span>
      <span class="marketing-delta up">+${formatarNumero(dados.youtube.inscritosNovos)} ganhos</span>
      ${
          dados.youtube.inscritosPerdidos > 0
              ? `<span class="marketing-delta down">−${formatarNumero(dados.youtube.inscritosPerdidos)} líquidos perdidos</span>`
              : ""
      }
    `;

    porId("marketingInstagramStats").innerHTML = `
      <span>Engajamento ${(
          (dados.instagram.likes + dados.instagram.comentarios) /
          Math.max(dados.instagram.views, 1)
      ).toLocaleString("pt-BR", { style: "percent", maximumFractionDigits: 1 })}</span>
      <span>Seguidores ${formatarNumero(dados.instagram.seguidores)}</span>
      <span class="marketing-delta ${classeDelta(dados.instagram.variacaoSeguidores)}">${formatarDelta(dados.instagram.variacaoSeguidores)} vs mês ant.</span>
    `;

    renderSerieViews("marketingYoutubeChart", dados.serieYoutube, "Inscritos (sync)");
    renderSerieSaldo("marketingYoutubeAudience", dados.serieYoutube);
    renderSerieViews("marketingInstagramChart", dados.serieInstagram, "Alcance");
    renderVideos("marketingYoutubeVideos", dados.videosYoutube ?? []);
    renderInsight(
        "marketingYoutubeInsight",
        dados.destaqueYoutube,
        "youtube",
        dados.youtube.saldoInscritos,
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
        porId("marketingYoutubeAudience").innerHTML = "";
        porId("marketingInstagramChart").innerHTML = "";
        porId("marketingYoutubeVideos").innerHTML = "";
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
