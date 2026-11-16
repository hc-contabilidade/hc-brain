/* =========================================================
   TELA MARKETING (rascunho)
   Ideias de vídeo + KPIs/gráficos YouTube e Instagram.
   ========================================================= */

import {
    atualizarIdeia,
    carregarMarketing,
    carregarPeriodosMarketing,
    criarIdeia,
    excluirIdeia,
    type IdeiaVideo,
    type ResumoMarketing,
    type SerieMensal,
} from "../dados/marketing.ts";
import { porId, selecao } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar } from "../comum/formato.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("marketing");

const filtroPeriodo = selecao("marketingMonthFilter");
const botaoNova = porId("marketingNovaIdeia");

const periodos = await carregarPeriodosMarketing();

filtroPeriodo.innerHTML = periodos
    .map(({ id, rotulo }) => `<option value="${id}">${rotulo}</option>`)
    .join("");
filtroPeriodo.disabled = periodos.length === 0;

function formatarNumero(valor: number): string {
    return new Intl.NumberFormat("pt-BR").format(valor);
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

function renderSerie(alvo: string, serie: SerieMensal[]): void {
    const maximo = Math.max(...serie.map(([, valor]) => valor), 1);
    porId(alvo).innerHTML = serie.length
        ? `<div class="marketing-bars">${serie
              .map(
                  ([rotulo, valor]) => `
            <div class="marketing-month-col">
              <span class="marketing-month-value">${formatarNumero(valor)}</span>
              <div class="marketing-month-bar-wrap"><div class="marketing-month-bar" style="height:${Math.max(6, (valor / maximo) * 100)}%"></div></div>
              <span class="marketing-month-label">${rotulo}</span>
            </div>`,
              )
              .join("")}</div>`
        : `<div class="empty-state">Sem métricas neste período.</div>`;
}

function renderIdeias(ideias: IdeiaVideo[]): void {
    porId("marketingIdeas").innerHTML = ideias.length
        ? ideias
              .map(
                  (ideia) => `
        <div class="marketing-idea-row" data-id="${ideia.id}">
          <div class="marketing-idea-main">
            <strong>${escapar(ideia.titulo)}</strong>
            <span>${escapar(ideia.descricao || "Sem descrição")} · ${rotuloPlataforma(ideia.plataforma)}</span>
          </div>
          <div class="marketing-idea-meta">
            <span class="marketing-idea-status">${rotuloStatus(ideia.status)}</span>
            <div>${escapar(ideia.responsavel || "—")} · ${ideia.prioridade}</div>
          </div>
          <div>
            <button type="button" class="btn-secundario" data-acao="aprovar">Avançar status</button>
            <button type="button" class="btn-secundario" data-acao="excluir">Excluir</button>
          </div>
        </div>`,
              )
              .join("")
        : `<div class="empty-state">Nenhuma ideia em rascunho ou aprovada.</div>`;
}

function renderMarketing(dados: ResumoMarketing): void {
    porId("marketingKpis").innerHTML = `
    <div class="marketing-kpi"><span class="marketing-kpi-label">YouTube · views</span><strong class="marketing-kpi-value">${formatarNumero(dados.youtube.views)}</strong><div class="marketing-kpi-foot">${formatarNumero(dados.youtube.likes)} likes</div></div>
    <div class="marketing-kpi"><span class="marketing-kpi-label">YouTube · inscritos</span><strong class="marketing-kpi-value">${formatarNumero(dados.youtube.inscritosOuAlcance)}</strong><div class="marketing-kpi-foot">no mês</div></div>
    <div class="marketing-kpi"><span class="marketing-kpi-label">Instagram · views</span><strong class="marketing-kpi-value">${formatarNumero(dados.instagram.views)}</strong><div class="marketing-kpi-foot">${formatarNumero(dados.instagram.likes)} likes</div></div>
    <div class="marketing-kpi"><span class="marketing-kpi-label">Instagram · alcance</span><strong class="marketing-kpi-value">${formatarNumero(dados.instagram.inscritosOuAlcance)}</strong><div class="marketing-kpi-foot">no mês</div></div>
  `;

    renderSerie("marketingYoutubeChart", dados.serieYoutube);
    renderSerie("marketingInstagramChart", dados.serieInstagram);
    renderIdeias(dados.ideias);
    filtroPeriodo.value = dados.periodo;
}

async function carregarEExibir(): Promise<void> {
    if (!periodos.length) {
        porId("marketingKpis").innerHTML =
            `<div class="empty-state">Nenhuma métrica de marketing na base ainda. Rode o seeder ou a sync quando as APIs existirem.</div>`;
        porId("marketingYoutubeChart").innerHTML = "";
        porId("marketingInstagramChart").innerHTML = "";
        porId("marketingIdeas").innerHTML = "";
        return;
    }

    renderMarketing(await carregarMarketing(obterSecao("marketing").periodo));
}

const PROXIMO_STATUS: Record<IdeiaVideo["status"], IdeiaVideo["status"]> = {
    rascunho: "aprovada",
    aprovada: "publicada",
    publicada: "arquivada",
    arquivada: "rascunho",
};

porId("marketingIdeas").addEventListener("click", async (evento) => {
    const botao = (evento.target as HTMLElement).closest<HTMLButtonElement>("button[data-acao]");
    const linha = (evento.target as HTMLElement).closest<HTMLElement>(".marketing-idea-row");
    if (!botao || !linha) {
        return;
    }

    const id = Number(linha.dataset.id);
    const acao = botao.dataset.acao;
    const dados = await carregarMarketing(obterSecao("marketing").periodo || filtroPeriodo.value);
    const ideia = dados.ideias.find((item) => item.id === id);
    if (!ideia) {
        return;
    }

    if (acao === "excluir") {
        if (!confirm(`Excluir a ideia "${ideia.titulo}"?`)) {
            return;
        }
        await excluirIdeia(id);
        await carregarEExibir();
        return;
    }

    if (acao === "aprovar") {
        await atualizarIdeia(id, {
            titulo: ideia.titulo,
            descricao: ideia.descricao,
            plataforma: ideia.plataforma,
            status: PROXIMO_STATUS[ideia.status],
            responsavel: ideia.responsavel,
            prioridade: ideia.prioridade,
        });
        await carregarEExibir();
    }
});

botaoNova.addEventListener("click", async () => {
    const titulo = prompt("Título da ideia de vídeo:");
    if (!titulo?.trim()) {
        return;
    }

    await criarIdeia({
        titulo: titulo.trim(),
        descricao: null,
        plataforma: "ambos",
        status: "rascunho",
        responsavel: null,
        prioridade: "media",
    });
    await carregarEExibir();
});

filtroPeriodo.addEventListener("change", () =>
    atualizarSecao("marketing", { periodo: filtroPeriodo.value }),
);

observarEstado(["marketing"], () => {
    void carregarEExibir();
});

await carregarEExibir();
