/* =========================================================
   TELA MARKETING — painel Redes HC
   ========================================================= */

import {
    carregarMarketing,
    carregarPeriodosMarketing,
    type IdeiaVideo,
    type PontoSerie,
    type ResumoMarketing,
    type VideoRanking,
} from "../dados/marketing.ts";
import { campo, porId, selecao } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar } from "../comum/formato.ts";
import { desenharIcones, icone } from "../comum/icones.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("marketing");

const filtroPeriodo = selecao("marketingMonthFilter");
const compararInput = campo("marketingCompare");
const ordenacaoConteudo = selecao("marketingContentSort");
const buscaIdeia = campo("marketingIdeaSearch");
const filtroCanalIdeia = selecao("marketingIdeaChannel");
const filtroStatusIdeia = selecao("marketingIdeaStatus");

const periodos = await carregarPeriodosMarketing();
const rotulosPeriodo = new Map(periodos.map((periodo) => [periodo.id, periodo.rotulo]));

filtroPeriodo.innerHTML = periodos
    .map(({ id, rotulo }) => `<option value="${id}">${rotulo}</option>`)
    .join("");
filtroPeriodo.disabled = periodos.length === 0;

const estado = {
    window: 6 as 3 | 6,
    compare: true,
    contentChannel: "all" as "all" | "yt" | "ig",
    sort: "views" as "views" | "likes" | "engagement",
};

let resumoAtual: ResumoMarketing | null = null;
const mesAtual = new Date().toISOString().slice(0, 7);

const number = new Intl.NumberFormat("pt-BR");
const decimal = new Intl.NumberFormat("pt-BR", {
    maximumFractionDigits: 1,
    minimumFractionDigits: 0,
});

function formatarNumero(valor: number): string {
    return number.format(valor);
}

function formatarCurto(valor: number): string {
    if (Math.abs(valor) >= 1000) {
        return `${decimal.format(valor / 1000)} mil`;
    }

    return formatarNumero(valor);
}

function formatarTaxa(valor: number | null): string {
    if (valor === null || Number.isNaN(valor)) {
        return "—";
    }

    return `${decimal.format(valor)}%`;
}

function capital(texto: string): string {
    return texto ? texto.charAt(0).toUpperCase() + texto.slice(1) : texto;
}

function mesAnterior(periodo: string): string {
    const [ano, mes] = periodo.split("-").map(Number);
    const data = new Date(ano, mes - 2, 1);

    return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, "0")}`;
}

function rotuloCurto(periodo: string): string {
    return capital((rotulosPeriodo.get(periodo) ?? periodo).split(" ")[0] ?? periodo);
}

function rotuloCompleto(periodo: string): string {
    return rotulosPeriodo.get(periodo) ?? periodo;
}

function engajamento(views: number, likes: number, comentarios: number): number | null {
    if (views <= 0) {
        return null;
    }

    return ((likes + comentarios) / views) * 100;
}

function wrapIcone(nome: Parameters<typeof icone>[0]): string {
    return `<span class="mkt-icon">${icone(nome)}</span>`;
}

function variacao(
    atual: number | null,
    anterior: number | null,
    modo: "percent" | "absolute" | "points",
): string {
    if (!estado.compare) {
        return "";
    }

    if (atual === null || anterior === null) {
        return `<span class="mkt-metric-delta">Sem histórico anterior</span>`;
    }

    const diferenca = atual - anterior;

    if (modo === "percent" && anterior === 0) {
        return `<span class="mkt-metric-delta">Sem base de comparação</span>`;
    }

    const valor =
        modo === "percent" ? (diferenca / anterior) * 100 : diferenca;
    const sinal = valor > 0 ? "+" : valor < 0 ? "−" : "";
    const montante =
        modo === "percent"
            ? `${decimal.format(Math.abs(valor))}%`
            : modo === "points"
              ? `${decimal.format(Math.abs(valor))} p.p.`
              : formatarNumero(Math.abs(valor));
    const tendencia = valor > 0 ? "up" : valor < 0 ? "down" : "";
    const seta =
        valor === 0
            ? ""
            : wrapIcone(valor > 0 ? "arrow-up" : "arrow-down");
    const referencia = rotuloCurto(mesAnterior(resumoAtual?.periodo ?? ""));

    return `<span class="mkt-metric-delta ${tendencia}">${seta}${sinal}${montante} vs ${escapar(referencia)}.</span>`;
}

function metricaPrincipal(
    rotulo: string,
    valor: string,
    delta: string,
    principal = false,
    ajuda = false,
): string {
    const info = ajuda
        ? `<button type="button" data-criteria aria-label="Ver cálculo de engajamento">${wrapIcone("circle-help")}</button>`
        : "";

    return `<div class="${principal ? "mkt-main-metric" : ""}">
      <span class="mkt-metric-label">${rotulo}${info}</span>
      <strong class="mkt-metric-value">${valor}</strong>
      ${delta}
    </div>`;
}

function tetoGrafico(valor: number): number {
    if (valor <= 0) {
        return 4;
    }

    const expoente = 10 ** Math.floor(Math.log10(valor));
    const escalado = valor / expoente;
    const passo = [1, 1.2, 1.6, 2, 2.4, 3, 4, 5, 6, 8, 10].find((n) => n >= escalado) ?? 10;

    return passo * expoente;
}

function historicoSerie(serie: PontoSerie[]): PontoSerie[] {
    return serie.slice(Math.max(0, serie.length - estado.window));
}

class GraficoCanal {
    private readonly canal: "yt" | "ig";
    private readonly wrap: HTMLElement;
    private readonly canvas: HTMLCanvasElement;
    private readonly context: CanvasRenderingContext2D | null;
    private readonly points: HTMLElement;
    private readonly tooltip: HTMLElement;
    private readonly observer: ResizeObserver;

    constructor(canal: "yt" | "ig") {
        this.canal = canal;
        this.wrap = porId(`${canal}-chart`);
        this.canvas = this.wrap.querySelector("canvas") as HTMLCanvasElement;
        this.context = this.canvas.getContext("2d");
        this.points = this.wrap.querySelector(".mkt-chart-points") as HTMLElement;
        this.tooltip = this.wrap.querySelector(".mkt-chart-tooltip") as HTMLElement;
        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.wrap);
        this.wrap.addEventListener("pointerleave", () => this.hideTooltip());
        this.points.addEventListener("pointerover", (event) => this.handlePoint(event));
        this.points.addEventListener("focusin", (event) => this.handlePoint(event));
        this.points.addEventListener("focusout", () => this.hideTooltip());
        this.points.addEventListener("click", (event) => this.handlePoint(event));
    }

    private handlePoint(event: Event): void {
        const botao = (event.target as HTMLElement | null)?.closest("[data-index]");

        if (!botao) {
            return;
        }

        this.showTooltip(Number((botao as HTMLElement).dataset.index), botao as HTMLElement);
    }

    private hideTooltip(): void {
        this.tooltip.hidden = true;
    }

    private showTooltip(index: number, botao: HTMLElement): void {
        if (!resumoAtual) {
            return;
        }

        const serie = historicoSerie(
            this.canal === "yt" ? resumoAtual.serieYoutube : resumoAtual.serieInstagram,
        );
        const ponto = serie[index];

        if (!ponto) {
            return;
        }

        const audienciaLabel = this.canal === "yt" ? "Inscritos" : "Seguidores";
        let html = `<strong>${escapar(capital(rotuloCompleto(ponto.periodo)))}</strong>`;
        html += `<span class="mkt-tooltip-row"><span>Visualizações</span><b>${formatarNumero(ponto.views)}</b></span>`;

        if (this.canal === "ig") {
            html += `<span class="mkt-tooltip-row"><span>Alcance</span><b>${formatarNumero(ponto.ganho)}</b></span>`;
        }

        html += `<span class="mkt-tooltip-row"><span>${audienciaLabel}</span><b>${formatarNumero(ponto.audiencia)}</b></span>`;
        this.tooltip.innerHTML = html;
        this.tooltip.hidden = false;
        const centro = botao.offsetLeft + botao.offsetWidth / 2;
        this.tooltip.style.left = `${Math.max(8, Math.min(centro - this.tooltip.offsetWidth / 2, this.wrap.clientWidth - this.tooltip.offsetWidth - 8))}px`;
        this.tooltip.style.top = "9px";
    }

    draw(): void {
        if (!resumoAtual || !this.context) {
            return;
        }

        const records = historicoSerie(
            this.canal === "yt" ? resumoAtual.serieYoutube : resumoAtual.serieInstagram,
        );

        if (!records.length) {
            return;
        }

        const width = this.wrap.clientWidth;
        const height = this.wrap.clientHeight;
        const pixelRatio = window.devicePixelRatio || 1;
        this.canvas.width = Math.round(width * pixelRatio);
        this.canvas.height = Math.round(height * pixelRatio);
        const ctx = this.context;
        ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const styles = getComputedStyle(this.wrap.closest(".marketing-page") ?? document.documentElement);
        const cor = (nome: string) => styles.getPropertyValue(nome).trim();
        const canalCor = cor(this.canal === "yt" ? "--mkt-yt" : "--mkt-ig");
        const softCor = cor(this.canal === "yt" ? "--mkt-yt-tint" : "--mkt-ig-tint");
        const linhaCor = cor("--mkt-teal");
        const muted = cor("--mkt-muted");
        const border = styles.getPropertyValue("--border").trim() || "#2b3d54";
        const ink = cor("--mkt-ink");
        const surface = styles.getPropertyValue("--surface").trim() || "#0c131c";
        const familia = getComputedStyle(document.body).fontFamily;
        const left = width < 370 ? 36 : 42;
        const right = 12;
        const top = 22;
        const bottom = height - 35;
        const plotHeight = bottom - top;
        const plotWidth = width - left - right;
        const ceiling = tetoGrafico(
            Math.max(...records.map((r) => Math.max(r.views, this.canal === "ig" ? r.ganho : 0))),
        );
        const step = plotWidth / records.length;

        ctx.textBaseline = "middle";
        ctx.font = `10px ${familia}`;

        for (let i = 0; i <= 4; i++) {
            const y = bottom - (plotHeight * i) / 4;
            ctx.lineWidth = 1;
            ctx.strokeStyle = border;
            ctx.setLineDash(i ? [3, 4] : []);
            ctx.beginPath();
            ctx.moveTo(left, y + 0.5);
            ctx.lineTo(width - right, y + 0.5);
            ctx.stroke();
            ctx.fillStyle = muted;
            ctx.textAlign = "right";
            ctx.fillText(formatarCurto((ceiling * i) / 4), left - 9, y);
        }

        ctx.setLineDash([]);
        const botoes: string[] = [];
        const pontosLinha: Array<{ x: number; y: number }> = [];

        records.forEach((record, index) => {
            const atual = record.periodo === resumoAtual?.periodo;
            const x = left + step * (index + 0.5);
            const barWidth = Math.min(records.length < 4 ? 48 : 38, step * 0.43);
            const barHeight = Math.max(record.views > 0 ? 2.5 : 0, (record.views / ceiling) * plotHeight);
            const barTop = bottom - barHeight;

            ctx.fillStyle = canalCor;
            ctx.globalAlpha = atual ? 1 : 0.22;
            ctx.beginPath();

            if (typeof (ctx as CanvasRenderingContext2D & { roundRect?: typeof ctx.rect }).roundRect === "function") {
                ctx.roundRect(x - barWidth / 2, barTop, barWidth, barHeight, [5, 5, 1, 1]);
            } else {
                ctx.rect(x - barWidth / 2, barTop, barWidth, barHeight);
            }

            ctx.fill();
            ctx.globalAlpha = 1;

            if (!atual) {
                ctx.strokeStyle = canalCor;
                ctx.globalAlpha = 0.14;
                ctx.lineWidth = 1;
                ctx.stroke();
                ctx.globalAlpha = 1;
            }

            ctx.font = `${atual ? "600 " : "400 "}10px ${familia}`;
            ctx.textAlign = "center";
            ctx.fillStyle = atual ? canalCor : muted;
            ctx.fillText(formatarCurto(record.views), x, barTop - 10);

            if (atual) {
                ctx.fillStyle = softCor;
                ctx.beginPath();

                if (typeof ctx.roundRect === "function") {
                    ctx.roundRect(x - 20, bottom + 10, 40, 21, 5);
                } else {
                    ctx.rect(x - 20, bottom + 10, 40, 21);
                }

                ctx.fill();
            }

            ctx.fillStyle = atual ? ink : muted;
            ctx.font = `${atual ? "600 " : "400 "}10px ${familia}`;
            ctx.fillText(capital(record.rotulo), x, bottom + 20);

            if (this.canal === "ig") {
                pontosLinha.push({
                    x,
                    y: bottom - (record.ganho / ceiling) * plotHeight,
                });
            }

            const rotuloAria = `${capital(rotuloCompleto(record.periodo))}, ${formatarNumero(record.views)} visualizações${
                this.canal === "ig" ? `, alcance ${formatarNumero(record.ganho)}` : ""
            }`;
            botoes.push(
                `<button type="button" class="mkt-chart-point" data-index="${index}" aria-label="${escapar(rotuloAria)}" style="left:${left + step * index}px;top:0;width:${step}px;height:${height}px"></button>`,
            );
        });

        if (pontosLinha.length) {
            ctx.strokeStyle = linhaCor;
            ctx.lineWidth = 2;
            ctx.beginPath();
            pontosLinha.forEach((ponto, index) =>
                index ? ctx.lineTo(ponto.x, ponto.y) : ctx.moveTo(ponto.x, ponto.y),
            );
            ctx.stroke();
            pontosLinha.forEach((ponto) => {
                ctx.fillStyle = surface;
                ctx.beginPath();
                ctx.arc(ponto.x, ponto.y, 3.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = linhaCor;
                ctx.lineWidth = 1.7;
                ctx.stroke();
            });
        }

        this.points.innerHTML = botoes.join("");
        this.hideTooltip();

        const primeiro = records[0];
        const ultimo = records[records.length - 1];
        porId(`${this.canal}-history-label`).textContent =
            `${capital(primeiro.rotulo)} – ${capital(ultimo.rotulo)} ${ultimo.periodo.slice(0, 4)}`;
    }
}

const graficos = [new GraficoCanal("yt"), new GraficoCanal("ig")];

function renderMetricas(): void {
    if (!resumoAtual) {
        return;
    }

    const yt = resumoAtual.youtube;
    const ig = resumoAtual.instagram;
    const ytAnteriorViews = yt.views - yt.variacaoViews;
    const ytAnteriorInscritos = yt.inscritosTotal - yt.variacaoAudiencia;
    const ytEng = engajamento(yt.views, yt.likes, yt.comentarios);
    const igAnteriorViews = ig.views - ig.variacaoViews;
    const igAnteriorSeguidores = ig.seguidores - ig.variacaoSeguidores;
    const igEng = engajamento(ig.views, ig.likes, ig.comentarios);

    porId("yt-primary").innerHTML =
        metricaPrincipal(
            "Visualizações",
            formatarNumero(yt.views),
            variacao(yt.views, ytAnteriorViews, "percent"),
            true,
        ) +
        metricaPrincipal(
            "Inscritos",
            formatarNumero(yt.inscritosTotal),
            variacao(yt.inscritosTotal, ytAnteriorInscritos > 0 ? ytAnteriorInscritos : null, "absolute"),
        ) +
        metricaPrincipal(
            "Engajamento",
            formatarTaxa(ytEng),
            estado.compare
                ? `<span class="mkt-metric-delta">${formatarNumero(yt.likes + yt.comentarios)} interações</span>`
                : "",
            false,
            true,
        );

    porId("yt-secondary").innerHTML = [
        ["Curtidas", yt.likes, "heart"],
        ["Comentários", yt.comentarios, "message-circle"],
        ["Compartilhamentos", yt.compartilhamentos, "share-2"],
    ]
        .map(
            ([rotulo, valor, glyph]) =>
                `<div class="mkt-secondary-metric"><span>${wrapIcone(glyph as "heart" | "message-circle" | "share-2")}${rotulo}</span><strong>${formatarNumero(valor as number)}</strong></div>`,
        )
        .join("");

    porId("ig-primary").innerHTML =
        metricaPrincipal(
            "Visualizações",
            formatarNumero(ig.views),
            variacao(ig.views, igAnteriorViews, "percent"),
            true,
        ) +
        metricaPrincipal(
            "Seguidores",
            formatarNumero(ig.seguidores),
            variacao(ig.seguidores, igAnteriorSeguidores > 0 || ig.variacaoSeguidores !== 0 ? igAnteriorSeguidores : null, "absolute"),
        ) +
        metricaPrincipal(
            "Engajamento",
            formatarTaxa(igEng),
            estado.compare ? `<span class="mkt-metric-delta">Alcance ${formatarNumero(ig.alcance)}</span>` : "",
            false,
            true,
        );

    porId("ig-secondary").innerHTML = [
        ["Curtidas", ig.likes, "heart"],
        ["Comentários", ig.comentarios, "message-circle"],
        ["Compartilhamentos", ig.compartilhamentos, "share-2"],
    ]
        .map(
            ([rotulo, valor, glyph]) =>
                `<div class="mkt-secondary-metric"><span>${wrapIcone(glyph as "heart" | "message-circle" | "share-2")}${rotulo}</span><strong>${formatarNumero(valor as number)}</strong></div>`,
        )
        .join("");
}

type ConteudoLinha = VideoRanking & { canal: "yt" | "ig" };

function conteudosDoResumo(): ConteudoLinha[] {
    if (!resumoAtual) {
        return [];
    }

    const youtube = (resumoAtual.videosYoutube ?? []).map((video) => ({
        ...video,
        canal: "yt" as const,
    }));
    const instagram = (resumoAtual.videosInstagram ?? []).map((video) => ({
        ...video,
        canal: "ig" as const,
    }));

    return [...youtube, ...instagram];
}

function renderConteudos(): void {
    const itens = conteudosDoResumo().filter(
        (item) => estado.contentChannel === "all" || item.canal === estado.contentChannel,
    );
    const pontuacao = (item: ConteudoLinha) =>
        estado.sort === "engagement"
            ? (engajamento(item.views, item.likes, item.comentarios) ?? -1)
            : item[estado.sort];

    itens.sort((a, b) => pontuacao(b) - pontuacao(a) || b.views - a.views);
    const maximo = Math.max(1, ...itens.map((item) => item.views));

    porId("marketingContents").innerHTML = itens.length
        ? itens
              .map((item, index) => {
                  const cor = item.canal === "yt" ? "var(--mkt-yt)" : "var(--mkt-ig)";
                  const ehShort = item.formato === "short";
                  const canalNome = item.canal === "yt" ? "YouTube" : "Instagram";
                  const formatoTag = ehShort
                      ? ' <span class="mkt-pill yt">Short</span>'
                      : "";
                  const href = item.url ?? "#";
                  const taxa = engajamento(item.views, item.likes, item.comentarios);

                  return `<tr class="mkt-content-row">
            <td><span class="mkt-medal ${index === 0 ? "first" : ""}">${index + 1}</span></td>
            <td class="mkt-table-content">
              <span class="mkt-content-name">${escapar(item.titulo)}</span>
              <span class="mkt-platform-tag" style="color:${cor}">${wrapIcone(item.canal === "yt" ? "video" : "image")}${canalNome}${formatoTag}</span>
            </td>
            <td class="number">${formatarNumero(item.views)}<div class="mkt-relative-track" aria-hidden="true"><span style="background:${cor};width:${(item.views / maximo) * 100}%"></span></div></td>
            <td class="number">${formatarNumero(item.likes)}</td>
            <td class="number">${formatarNumero(item.comentarios)}</td>
            <td class="number">${formatarTaxa(taxa)}</td>
            <td><a class="mkt-icon-btn" href="${escapar(href)}" target="_blank" rel="noopener noreferrer" aria-label="Abrir ${escapar(item.titulo)}">${wrapIcone("external-link")}</a></td>
          </tr>`;
              })
              .join("")
        : `<tr><td class="mkt-empty-cell" colspan="7">Nenhum conteúdo armazenado para este mês e canal.</td></tr>`;

    porId("marketingContentCount").textContent = `${itens.length} ${
        itens.length === 1 ? "conteúdo" : "conteúdos"
    } · ${capital(rotuloCompleto(resumoAtual?.periodo ?? ""))}`;

    document.querySelectorAll<HTMLButtonElement>("[data-content-channel]").forEach((botao) => {
        botao.setAttribute(
            "aria-pressed",
            String(botao.dataset.contentChannel === estado.contentChannel),
        );
    });
}

function canaisIdeia(plataforma: IdeiaVideo["plataforma"]): string[] {
    if (plataforma === "ambos") {
        return ["yt", "ig"];
    }

    return [plataforma === "youtube" ? "yt" : "ig"];
}

function rotuloStatus(status: IdeiaVideo["status"]): string {
    return {
        rascunho: "Rascunho",
        aprovada: "Aprovada",
        publicada: "Publicada",
        arquivada: "Arquivada",
    }[status];
}

function classeStatus(status: IdeiaVideo["status"]): string {
    if (status === "aprovada") {
        return "approved";
    }

    if (status === "publicada") {
        return "published";
    }

    return "draft";
}

function normalizar(texto: string): string {
    return texto
        .normalize("NFD")
        .replace(/\p{M}/gu, "")
        .toLowerCase();
}

function renderIdeias(): void {
    const ideias = resumoAtual?.ideias ?? [];
    const busca = normalizar(buscaIdeia.value);
    const termos = busca.split(/\s+/).filter(Boolean);
    const canal = filtroCanalIdeia.value;
    const status = filtroStatusIdeia.value;
    const filtradas = ideias.filter((ideia) => {
        if (canal && ideia.plataforma !== canal) {
            return false;
        }

        if (status && ideia.status !== status) {
            return false;
        }

        const base = normalizar(`${ideia.titulo} ${ideia.descricao ?? ""} ${ideia.responsavel ?? ""}`);

        return termos.every((termo) => base.includes(termo));
    });

    porId("marketingIdeas").innerHTML = filtradas.length
        ? filtradas
              .map((ideia) => {
                  const pills = canaisIdeia(ideia.plataforma)
                      .map((ch) => `<span class="mkt-pill ${ch}">${ch === "yt" ? "YT" : "IG"}</span>`)
                      .join("");

                  return `<tr class="mkt-idea-row" data-idea-row="${ideia.id}">
            <td class="mkt-idea-name">
              <button type="button" class="mkt-idea-open" data-idea="${ideia.id}" aria-label="Consultar ideia ${escapar(ideia.titulo)}">${escapar(ideia.titulo)}</button>
              <p class="mkt-idea-desc">${escapar(ideia.descricao || "—")}</p>
            </td>
            <td><span class="mkt-row-start">${pills}</span></td>
            <td style="color:var(--mkt-muted);font-size:11px">${escapar(ideia.responsavel || "—")}</td>
            <td><span class="mkt-pill ${classeStatus(ideia.status)}"><span class="mkt-status-dot"></span>${rotuloStatus(ideia.status)}</span></td>
            <td style="color:var(--mkt-muted)">${wrapIcone("chevron-right")}</td>
          </tr>`;
              })
              .join("")
        : `<tr><td class="mkt-empty-cell" colspan="5">Nenhuma ideia corresponde aos filtros atuais.</td></tr>`;

    porId("marketingIdeaCount").textContent = `${filtradas.length} de ${ideias.length} ideias`;
    porId("marketingIdeasTotal").textContent = `${ideias.length} ${
        ideias.length === 1 ? "registro" : "registros"
    }`;
}

function abrirIdeia(id: number): void {
    const ideia = resumoAtual?.ideias.find((item) => item.id === id);

    if (!ideia) {
        return;
    }

    porId("marketingIdeaTitle").textContent = ideia.titulo;
    porId("marketingIdeaBody").innerHTML = `
      <p style="color:var(--texto-suave);line-height:1.65;margin:0">${escapar(ideia.descricao || "Sem descrição.")}</p>
      <dl class="mkt-meta-grid">
        <div><dt>Canal</dt><dd>${ideia.plataforma === "ambos" ? "YouTube + Instagram" : ideia.plataforma === "youtube" ? "YouTube" : "Instagram"}</dd></div>
        <div><dt>Responsável</dt><dd>${escapar(ideia.responsavel || "—")}</dd></div>
        <div><dt>Status</dt><dd>${rotuloStatus(ideia.status)}</dd></div>
        <div><dt>Prioridade</dt><dd>${escapar(ideia.prioridade)}</dd></div>
      </dl>`;
    (porId("marketingIdeaDialog") as HTMLDialogElement).showModal();
    desenharIcones(porId("marketingIdeaDialog"));
}

function rotuloMesHistorico(periodo: string): string {
    const base = rotulosPeriodo.get(periodo);

    if (base) {
        const partes = base.trim().split(/\s+/);
        const mes = partes[0] ?? periodo;
        const ano = partes[1] ?? periodo.slice(0, 4);

        return `${capital(mes)} de ${ano}`;
    }

    return periodo;
}

function valorOuTraco(valor: number): string {
    return valor > 0 ? formatarNumero(valor) : "—";
}

function notaHistorico(serie: PontoSerie[]): string {
    const parcial = serie.find((ponto) => ponto.periodo === mesAtual);
    const hoje = new Date();
    const dataParcial = hoje.toLocaleDateString("pt-BR");

    if (parcial) {
        const mesNome = capital((rotulosPeriodo.get(mesAtual) ?? mesAtual).split(" ")[0] ?? "Mês");

        return `Traço = histórico indisponível. ${mesNome} contém dados parciais até ${dataParcial}.`;
    }

    return "Traço = histórico indisponível.";
}

function mostrarDados(canal: "yt" | "ig"): void {
    if (!resumoAtual) {
        return;
    }

    const serie = historicoSerie(canal === "yt" ? resumoAtual.serieYoutube : resumoAtual.serieInstagram);
    const audiencia = canal === "yt" ? "Inscritos" : "Seguidores";
    const extraCabecalho = canal === "ig" ? `<th class="number">Alcance</th>` : "";
    const linhas = serie
        .map((ponto) => {
            const extra =
                canal === "ig" ? `<td class="number">${valorOuTraco(ponto.ganho)}</td>` : "";

            return `<tr>
          <td>${escapar(rotuloMesHistorico(ponto.periodo))}</td>
          <td class="number">${valorOuTraco(ponto.views)}</td>
          ${extra}
          <td class="number">${valorOuTraco(ponto.audiencia)}</td>
        </tr>`;
        })
        .join("");

    porId("marketingDataTitle").textContent =
        `${canal === "yt" ? "YouTube" : "Instagram"} · dados do gráfico`;
    porId("marketingDataBody").innerHTML = `
      <div class="mkt-table-wrap">
        <table class="mkt-data-table">
          <thead>
            <tr>
              <th>Mês</th>
              <th class="number">Visualizações</th>
              ${extraCabecalho}
              <th class="number">${audiencia}</th>
            </tr>
          </thead>
          <tbody>${linhas || `<tr><td class="mkt-empty-cell" colspan="${canal === "ig" ? 4 : 3}">Sem histórico para esta janela.</td></tr>`}</tbody>
        </table>
      </div>
      <p class="mkt-data-note">${escapar(notaHistorico(serie))}</p>`;
    (porId("marketingDataDialog") as HTMLDialogElement).showModal();
    desenharIcones(porId("marketingDataDialog"));
}

function renderPainel(): void {
    if (!resumoAtual) {
        return;
    }

    const parcial = resumoAtual.periodo === mesAtual;
    porId("marketingPeriodStatus").innerHTML = parcial
        ? `<span class="mkt-partial-pill"><span class="mkt-status-dot"></span>Mês em andamento</span>`
        : "";

    const nota = parcial
        ? `Dados parciais até a última sync. ${
              estado.compare
                  ? "A comparação considera o mês anterior completo."
                  : "O mês ainda está em andamento."
          }`
        : `${capital(rotuloCompleto(resumoAtual.periodo))}${
              estado.compare
                  ? ` · comparação com ${rotuloCompleto(mesAnterior(resumoAtual.periodo))}.`
                  : " · comparação desativada."
          }`;

    porId("marketingPeriodNote").innerHTML = `${wrapIcone(parcial ? "circle-help" : "calendar")}<span>${nota}</span>`;
    porId("marketingFooterNote").textContent =
        `Dados das fontes integradas · período ${rotuloCompleto(resumoAtual.periodo)}`;

    renderMetricas();
    renderConteudos();
    renderIdeias();
    graficos.forEach((grafico) => grafico.draw());

    document.querySelectorAll<HTMLButtonElement>("[data-window]").forEach((botao) => {
        botao.setAttribute("aria-pressed", String(Number(botao.dataset.window) === estado.window));
    });

    desenharIcones(porId("marketing"));
}

function aplicarLinks(dados: ResumoMarketing): void {
    (porId("marketingLinkYoutube") as HTMLAnchorElement).href = dados.canais.youtube;
    (porId("marketingLinkInstagram") as HTMLAnchorElement).href = dados.canais.instagram;
    (porId("marketingCanalYoutube") as HTMLAnchorElement).href = dados.canais.youtube;
    (porId("marketingCanalInstagram") as HTMLAnchorElement).href = dados.canais.instagram;
}

async function carregarEExibir(): Promise<void> {
    if (!periodos.length) {
        porId("yt-primary").innerHTML = "";
        porId("ig-primary").innerHTML = "";
        porId("yt-secondary").innerHTML = "";
        porId("ig-secondary").innerHTML = "";
        porId("marketingContents").innerHTML =
            `<tr><td class="mkt-empty-cell" colspan="7">Nenhuma métrica de marketing na base ainda.</td></tr>`;
        porId("marketingIdeas").innerHTML =
            `<tr><td class="mkt-empty-cell" colspan="5">Nenhuma ideia na fila.</td></tr>`;
        porId("marketingPeriodNote").textContent = "Nenhuma métrica sincronizada ainda.";
        return;
    }

    resumoAtual = await carregarMarketing(obterSecao("marketing").periodo);
    filtroPeriodo.value = resumoAtual.periodo;
    aplicarLinks(resumoAtual);
    renderPainel();
}

filtroPeriodo.addEventListener("change", () =>
    atualizarSecao("marketing", { periodo: filtroPeriodo.value }),
);

compararInput.addEventListener("change", () => {
    estado.compare = compararInput.checked;
    renderPainel();
});

ordenacaoConteudo.addEventListener("change", () => {
    estado.sort = ordenacaoConteudo.value as typeof estado.sort;
    renderConteudos();
    desenharIcones(porId("marketingContents"));
});

buscaIdeia.addEventListener("input", () => {
    renderIdeias();
    desenharIcones(porId("marketingIdeas"));
});
filtroCanalIdeia.addEventListener("change", () => {
    renderIdeias();
    desenharIcones(porId("marketingIdeas"));
});
filtroStatusIdeia.addEventListener("change", () => {
    renderIdeias();
    desenharIcones(porId("marketingIdeas"));
});

porId("marketingIdeaClear").addEventListener("click", () => {
    buscaIdeia.value = "";
    filtroCanalIdeia.value = "";
    filtroStatusIdeia.value = "";
    renderIdeias();
    desenharIcones(porId("marketingIdeas"));
});

document.querySelectorAll<HTMLButtonElement>("[data-window]").forEach((botao) => {
    botao.addEventListener("click", () => {
        estado.window = Number(botao.dataset.window) as 3 | 6;
        renderPainel();
    });
});

document.querySelectorAll<HTMLButtonElement>("[data-content-channel]").forEach((botao) => {
    botao.addEventListener("click", () => {
        estado.contentChannel = botao.dataset.contentChannel as typeof estado.contentChannel;
        renderConteudos();
        desenharIcones(porId("marketingContents"));
    });
});

document.addEventListener("click", (event) => {
    const alvo = event.target as HTMLElement | null;

    if (!alvo) {
        return;
    }

    if (alvo.closest("[data-criteria]")) {
        (porId("marketingCriteriaDialog") as HTMLDialogElement).showModal();
        desenharIcones(porId("marketingCriteriaDialog"));
        return;
    }

    const fechar = alvo.closest<HTMLElement>("[data-close]");

    if (fechar?.dataset.close) {
        (porId(fechar.dataset.close) as HTMLDialogElement).close();
        return;
    }

    const dados = alvo.closest<HTMLElement>("[data-data]");

    if (dados?.dataset.data === "yt" || dados?.dataset.data === "ig") {
        mostrarDados(dados.dataset.data);
        return;
    }

    const ideia = alvo.closest<HTMLElement>("[data-idea]");

    if (ideia?.dataset.idea) {
        abrirIdeia(Number(ideia.dataset.idea));
        return;
    }

    const linha = alvo.closest<HTMLElement>("[data-idea-row]");

    if (linha?.dataset.ideaRow && !alvo.closest("button")) {
        abrirIdeia(Number(linha.dataset.ideaRow));
    }
});

observarEstado(["marketing"], () => {
    void carregarEExibir();
});

await carregarEExibir();
desenharIcones(porId("marketing"));
