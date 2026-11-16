/* =========================================================
   TELA COMERCIAL
   Espelha o Painel de Vendas do Automacao: vendas em contagem
   (abertura/transferência), leads, reuniões e perdas.
   ========================================================= */

import {
    carregarComercial,
    carregarPeriodosComerciais,
    type IndicadoresComerciais,
    type OrigemLead,
} from "../dados/comercial.ts";
import { porId, selecao } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar, plural } from "../comum/formato.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("comercial");

const filtroPeriodo = selecao("commercialMonthFilter");

const periodos = await carregarPeriodosComerciais();

filtroPeriodo.innerHTML = periodos
    .map(({ id, rotulo }) => `<option value="${id}">${rotulo}</option>`)
    .join("");
filtroPeriodo.disabled = periodos.length === 0;

function gradienteOrigens(origens: OrigemLead[], total: number): string {
    let cursor = 0;
    const paradas = origens.map(([, valor, cor]) => {
        const inicio = cursor;
        cursor += (valor / Math.max(total, 1)) * 100;
        return `${cor} ${inicio.toFixed(2)}% ${cursor.toFixed(2)}%`;
    });
    return `conic-gradient(${paradas.join(",")})`;
}

function porcentagem(valor: number): string {
    return `${valor.toFixed(1).replace(".", ",")}%`;
}

function variacaoDeLeads(dados: IndicadoresComerciais): string {
    if (!dados.leadsAnteriores) {
        return `<span>primeiro mês da base</span>`;
    }

    const variacao =
        ((dados.leads - dados.leadsAnteriores) / dados.leadsAnteriores) * 100;
    const direcao = variacao < 0 ? "down" : "up";
    const sinal = variacao < 0 ? "" : "+";

    return `<span class="commercial-delta ${direcao}">${sinal}${porcentagem(variacao)}</span><span>vs. mês anterior</span>`;
}

function renderComercial(dados: IndicadoresComerciais): void {
    const conversao = dados.leads ? (dados.closed / dados.leads) * 100 : 0;
    const qualificacao = dados.leads ? (dados.qualified / dados.leads) * 100 : 0;
    const perdas = dados.losses.reduce((soma, [, valor]) => soma + valor, 0);
    const funil =
        dados.funnel.length > 0
            ? dados.funnel
            : ([
                  ["Leads recebidos", dados.leads],
                  ["Qualificados", dados.qualified],
                  ["Reuniões", dados.meetings],
                  ["Propostas", dados.proposals],
                  ["Vendas fechadas", dados.closed],
              ] as [string, number][]);

    porId("commercialKpis").innerHTML = `
    <div class="commercial-kpi"><span class="commercial-kpi-label">Vendas do mês</span><strong class="commercial-kpi-value">${dados.closed}</strong><div class="commercial-kpi-foot"><span>Abertura ${dados.abertura}</span><span>Transf. ${dados.transferencia}</span></div></div>
    <div class="commercial-kpi"><span class="commercial-kpi-label">Reuniões do mês</span><strong class="commercial-kpi-value">${dados.meetings}</strong><div class="commercial-kpi-foot"><span>Agendadas ${dados.meetings}</span><span>Realizadas ${dados.meetingsDone}</span></div></div>
    <div class="commercial-kpi"><span class="commercial-kpi-label">Leads do mês</span><strong class="commercial-kpi-value">${dados.leads}</strong><div class="commercial-kpi-foot"><span>Qualif. ${dados.qualified}</span><span>Rebotes ${dados.rebotes}</span></div></div>
    <div class="commercial-kpi"><span class="commercial-kpi-label">Conversão</span><strong class="commercial-kpi-value">${porcentagem(conversao)}</strong><div class="commercial-kpi-foot">${variacaoDeLeads(dados)}</div></div>
    <div class="commercial-kpi"><span class="commercial-kpi-label">Em processo</span><strong class="commercial-kpi-value">${dados.inProcess}</strong><div class="commercial-kpi-foot"><span class="commercial-delta neutral">${dados.proposals}</span><span>propostas enviadas</span></div></div>
  `;

    const maximo = Math.max(...funil.map(([, valor]) => valor), 1);

    porId("commercialFunnel").innerHTML = funil
        .map(
            ([nome, valor]) => `
        <div class="commercial-funnel-row">
          <span class="commercial-funnel-name">${escapar(nome)}</span>
          <div class="commercial-funnel-track"><div class="commercial-funnel-fill" style="width:${Math.max(7, (valor / maximo) * 100)}%"></div></div>
          <strong class="commercial-funnel-value">${valor}</strong>
        </div>
      `,
        )
        .join("");

    porId("commercialRates").innerHTML = `
    <div class="commercial-rate"><span>Taxa de qualificação</span><strong>${porcentagem(qualificacao)}</strong><small>leads que avançaram</small></div>
    <div class="commercial-rate"><span>Leads → venda</span><strong>${porcentagem(conversao)}</strong><small>conversão no período</small></div>
    <div class="commercial-rate"><span>Reuniões realizadas</span><strong>${dados.meetingsDone}</strong><small>de ${dados.meetings} agendadas</small></div>
    <div class="commercial-rate"><span>Perdas registradas</span><strong>${perdas}</strong><small>leads sem fechamento</small></div>
  `;

    const maximoMensal = Math.max(...dados.monthly.map(([, valor]) => valor), 1);
    porId("commercialMonthlyLeads").innerHTML = `
    <div class="commercial-bars">${dados.monthly
        .map(
            ([rotulo, valor]) => `
          <div class="commercial-month-col">
            <span class="commercial-month-value">${valor}</span>
            <div class="commercial-month-bar-wrap"><div class="commercial-month-bar" style="height:${Math.max(6, (valor / maximoMensal) * 100)}%"></div></div>
            <span class="commercial-month-label">${rotulo}</span>
          </div>
        `,
        )
        .join("")}</div>
  `;

    const totalOrigens = dados.origins.reduce((soma, [, valor]) => soma + valor, 0);
    const totalDonut = totalOrigens || dados.leads;

    porId("commercialOriginTotal").textContent = String(totalDonut);
    porId("commercialOriginLegend").innerHTML = dados.origins
        .map(
            ([nome, valor, cor]) => `
        <div class="commercial-legend-row"><i class="commercial-legend-dot" style="background:${cor}"></i><span>${escapar(nome)}</span><strong>${valor}</strong></div>
      `,
        )
        .join("");

    porId("commercialOriginDonut").style.background = totalDonut
        ? gradienteOrigens(dados.origins, totalDonut)
        : "var(--surface-alto)";

    porId("commercialSourceLegend").innerHTML = (dados.sources ?? [])
        .map(
            ([nome, valor, cor]) => `
        <div class="commercial-legend-row"><i class="commercial-legend-dot" style="background:${cor}"></i><span>${escapar(nome)}</span><strong>${valor}</strong></div>
      `,
        )
        .join("") || `<div class="empty-state">Sem fontes no período.</div>`;

    const maximoPerda = Math.max(...dados.losses.map(([, valor]) => valor), 1);
    porId("commercialLostTotal").textContent = plural(perdas, "perda", "perdas");
    porId("commercialLossReasons").innerHTML = dados.losses.length
        ? dados.losses
              .map(
                  ([nome, valor]) => `
        <div class="commercial-loss-row">
          <span class="commercial-loss-name">${escapar(nome)}</span>
          <div class="commercial-loss-track"><div class="commercial-loss-fill" style="width:${(valor / maximoPerda) * 100}%"></div></div>
          <strong class="commercial-loss-count">${valor}</strong>
        </div>
      `,
              )
              .join("")
        : `<div class="empty-state">Nenhuma perda registrada neste período.</div>`;

    porId("commercialSalesList").innerHTML = dados.sales.length
        ? dados.sales
              .map(
                  ([empresa, responsavel, tipo]) => `
        <div class="commercial-sale-row">
          <div class="commercial-sale-avatar">${escapar(empresa.slice(0, 2).toUpperCase())}</div>
          <div class="commercial-sale-main"><strong>${escapar(empresa)}</strong><span>${escapar(responsavel)}</span></div>
          <span class="commercial-sale-value">${escapar(tipo)}</span>
        </div>
      `,
              )
              .join("")
        : `<div class="empty-state">Nenhuma venda fechada neste período.</div>`;

    porId("commercialTeamBody").innerHTML = dados.team.length
        ? [...dados.team]
              .sort((a, b) => b[2] - a[2])
              .map(
                  (linha, indice) => `
        <tr>
          <td>${String(indice + 1).padStart(2, "0")}</td>
          <td><strong>${escapar(linha[0])}</strong></td>
          <td>${linha[1]}</td>
          <td>${linha[2]}</td>
          <td>${porcentagem(linha[3])}</td>
        </tr>
      `,
              )
              .join("")
        : `<tr><td colspan="5"><div class="empty-state">Sem responsáveis no período.</div></td></tr>`;

    filtroPeriodo.value = dados.periodo;
}

function renderSemPeriodos(): void {
    const vazio = `<div class="empty-state">Nenhum período comercial na base ainda. Aguarde a sincronização com o Automacao ou rode hc:sincronizar-comercial.</div>`;

    porId("commercialKpis").innerHTML = vazio;
    porId("commercialFunnel").innerHTML = "";
    porId("commercialRates").innerHTML = "";
    porId("commercialMonthlyLeads").innerHTML = "";
    porId("commercialOriginLegend").innerHTML = "";
    porId("commercialOriginTotal").textContent = "0";
    porId("commercialOriginDonut").style.background = "var(--surface-alto)";
    porId("commercialSourceLegend").innerHTML = "";
    porId("commercialLostTotal").textContent = "0 perdas";
    porId("commercialLossReasons").innerHTML = "";
    porId("commercialSalesList").innerHTML = "";
    porId("commercialTeamBody").innerHTML =
        `<tr><td colspan="5">${vazio}</td></tr>`;
}

async function carregarEExibir(): Promise<void> {
    if (!periodos.length) {
        renderSemPeriodos();
        return;
    }

    renderComercial(await carregarComercial(obterSecao("comercial").periodo));
}

filtroPeriodo.addEventListener("change", () =>
    atualizarSecao("comercial", { periodo: filtroPeriodo.value }),
);

observarEstado(["comercial"], () => {
    void carregarEExibir();
});

await carregarEExibir();
