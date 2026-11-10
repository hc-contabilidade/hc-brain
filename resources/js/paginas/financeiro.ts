/* =========================================================
   TELA FINANCEIRO GERAL
   Consolida a carteira a partir da mesma base de empresas usada
   no detalhe de cada cliente, então os números batem entre as
   duas telas. Busca e regime ficam salvos no estado.
   ========================================================= */

import { ErroDeApi } from "../comum/api.ts";
import {
    carregarCarteira,
    carregarEmpresasDaLista,
    carregarOmieApps,
    excluirOmieApp,
    salvarOmieApp,
    COR_PADRAO_DO_GRAFICO,
    corDoRegime,
    REGIMES_TRIBUTARIOS,
    type EmpresaDaLista,
    type LinhaCarteira,
    type OmieAppResumo,
    type RegimeTributario,
} from "../dados/empresas.ts";
import { combina, termoDeBusca } from "../comum/busca.ts";
import { campo, porId, selecao, todos } from "../comum/dom.ts";
import { atualizarSecao, observarEstado, obterSecao } from "../comum/estado.ts";
import { escapar, duracaoEmMeses, moeda, plural } from "../comum/formato.ts";
import { caminhoDaPagina } from "../comum/paginas.ts";
import { iniciarPagina } from "../comum/shell.ts";

type ValorFinanceiro = "primeiro" | "atual" | "total";

iniciarPagina("financeiro");

const busca = campo("financeEmpresaSearch");
const regime = selecao("financeRegimeFilter");

const carteira = await carregarCarteira();
const empresas = await carregarEmpresasDaLista();

function linhas(): LinhaCarteira[] {
    const estado = obterSecao("financeiro");
    const palavras = termoDeBusca(estado.busca);

    return carteira.filter((item) => {
        const texto = `${item.nome} ${item.setor} ${item.socio} ${item.regime}`;
        return combina(texto, palavras) && regimeSelecionado(estado.regime, item.regime);
    });
}

function regimeDoFiltro(valor: string): string {
    return valor === "Todos" || (REGIMES_TRIBUTARIOS as readonly string[]).includes(valor)
        ? valor
        : "Todos";
}

function regimeSelecionado(filtro: string, regime: string): boolean {
    const escolhido = regimeDoFiltro(filtro);

    return escolhido === "Todos" || escolhido === regime;
}

/** Só os quatro regimes do filtro, na ordem em que aparecem no gráfico. */
function porRegime(dados: LinhaCarteira[]): [RegimeTributario, LinhaCarteira[]][] {
    return REGIMES_TRIBUTARIOS.flatMap((nome) => {
        const lista = dados.filter((item) => item.regime === nome);
        return lista.length ? [[nome, lista] as [RegimeTributario, LinhaCarteira[]]] : [];
    });
}

function renderKpis(dados: LinhaCarteira[]): void {
    const total = dados.reduce((soma, item) => soma + item.total, 0);
    const media = (chave: ValorFinanceiro) =>
        dados.length
            ? dados.reduce((soma, item) => soma + item[chave], 0) / dados.length
            : 0;
    const mesesMedios = dados.length
        ? dados.reduce((soma, item) => soma + item.meses, 0) / dados.length
        : 0;

    const kpis: [string, string, string][] = [
        ["LTV médio", moeda(media("total")), "valor médio acumulado por cliente"],
        ["Valor total arrecadado", moeda(total), "carteira representada pelo filtro"],
        ["1º honorário médio", moeda(media("primeiro")), "valor médio na entrada"],
        ["Honorário atual médio", moeda(media("atual")), "valor médio praticado hoje"],
        [
            "Tempo médio de vida",
            duracaoEmMeses(Math.round(mesesMedios)),
            "tempo médio de relacionamento",
        ],
    ];

    porId("financeKpis").innerHTML = kpis
        .map(
            ([rotulo, valor, nota]) => `
        <div class="finance-kpi">
          <span class="finance-kpi-label">${rotulo}</span><strong>${valor}</strong><small>${nota}</small>
        </div>
      `,
        )
        .join("");
}

function renderLtv(dados: LinhaCarteira[]): void {
    const valores: [RegimeTributario, number][] = porRegime(dados).map(([nome, lista]) => [
        nome,
        lista.reduce((soma, item) => soma + item.total, 0) / lista.length,
    ]);
    const maximo = Math.max(...valores.map(([, valor]) => valor), 1);

    porId("financeLtvChart").innerHTML = valores.length
        ? `<div class="finance-bars">${valores
              .map(
                  ([nome, valor]) => `
            <div class="finance-bar-row">
              <span class="finance-bar-name">${nome}</span>
              <div class="finance-bar-track"><div class="finance-bar-fill" style="width:${(valor / maximo) * 100}%;background:${corDoRegime[nome] ?? COR_PADRAO_DO_GRAFICO};"></div></div>
              <span class="finance-bar-value">${moeda(valor)}</span>
            </div>
          `,
              )
              .join("")}</div>`
        : `<div class="finance-empty">Nenhuma empresa corresponde aos filtros.</div>`;
}

function renderDonut(dados: LinhaCarteira[]): void {
    const totais: [RegimeTributario, number][] = porRegime(dados).map(([nome, lista]) => [
        nome,
        lista.reduce((soma, item) => soma + item.total, 0),
    ]);
    const total = totais.reduce((soma, [, valor]) => soma + valor, 0);

    porId("financeDonutTotal").textContent = moeda(total);

    let acumulado = 0;
    const paradas = totais.map(([nome, valor]) => {
        const inicio = total ? (acumulado / total) * 360 : 0;
        acumulado += valor;
        const fim = total ? (acumulado / total) * 360 : 360;
        return `${corDoRegime[nome] ?? COR_PADRAO_DO_GRAFICO} ${inicio}deg ${fim}deg`;
    });

    porId("financeDonut").style.background = total
        ? `conic-gradient(${paradas.join(",")})`
        : "var(--surface-alto)";

    porId("financeLegend").innerHTML = totais.length
        ? totais
              .map(
                  ([nome, valor]) => `
            <div class="finance-legend-row">
              <i class="finance-legend-dot" style="background:${corDoRegime[nome] ?? COR_PADRAO_DO_GRAFICO}"></i>
              <span>${nome}</span>
              <strong>${total ? Math.round((valor / total) * 100) : 0}%</strong>
            </div>
          `,
              )
              .join("")
        : `<div class="finance-empty">Sem dados.</div>`;
}

function renderHonorarios(dados: LinhaCarteira[]): void {
    const valores: [RegimeTributario, number, number][] = porRegime(dados).map(
        ([nome, lista]) => [
            nome,
            lista.reduce((soma, item) => soma + item.primeiro, 0) / lista.length,
            lista.reduce((soma, item) => soma + item.atual, 0) / lista.length,
        ],
    );
    const maximo = Math.max(
        ...valores.flatMap(([, entrada, atual]) => [entrada, atual]),
        1,
    );

    porId("financeHonorariumChart").innerHTML = valores.length
        ? `<div class="finance-comparison">${valores
              .map(
                  ([nome, entrada, atual]) => `
            <div class="finance-comparison-row">
              <span class="finance-comparison-label">${nome}</span>
              <div class="finance-comparison-lines">
                <div class="finance-comparison-line"><span>Entrada</span><div class="finance-comparison-track"><div class="finance-comparison-fill" style="width:${(entrada / maximo) * 100}%;"></div></div><b class="finance-comparison-value">${moeda(entrada)}</b></div>
                <div class="finance-comparison-line"><span>Atual</span><div class="finance-comparison-track"><div class="finance-comparison-fill current" style="width:${(atual / maximo) * 100}%;"></div></div><b class="finance-comparison-value">${moeda(atual)}</b></div>
              </div>
            </div>
          `,
              )
              .join("")}</div>`
        : `<div class="finance-empty">Nenhuma empresa corresponde aos filtros.</div>`;
}

function renderConcentracao(dados: LinhaCarteira[]): void {
    const ordenadas = [...dados].sort((a, b) => b.total - a.total).slice(0, 5);
    const maximo = Math.max(...ordenadas.map((item) => item.total), 1);

    porId("financeTopClients").innerHTML = ordenadas.length
        ? `<div class="finance-top-list">${ordenadas
              .map(
                  (item, indice) => `
            <div class="finance-top-row">
              <span class="finance-top-rank">${String(indice + 1).padStart(2, "0")}</span>
              <span class="finance-top-name">${escapar(item.nome)}</span>
              <div class="finance-top-track"><div class="finance-top-fill" style="width:${(item.total / maximo) * 100}%;"></div></div>
              <b class="finance-top-value">${moeda(item.total)}</b>
            </div>
          `,
              )
              .join("")}</div>`
        : `<div class="finance-empty">Nenhuma empresa corresponde aos filtros.</div>`;
}

function renderInsights(dados: LinhaCarteira[]): void {
    const caixa = porId("financeInsights");

    if (!dados.length) {
        caixa.innerHTML = "";
        return;
    }

    const maiorLtv = [...dados].sort((a, b) => b.total - a.total)[0];
    const maiorHonorario = [...dados].sort((a, b) => b.atual - a.atual)[0];
    const crescimentoMedio =
        (dados.reduce(
            (soma, item) =>
                soma + (item.atual - item.primeiro) / Math.max(item.primeiro, 1),
            0,
        ) /
            dados.length) *
        100;

    caixa.innerHTML = `
    <div class="finance-insight"><span>Maior LTV</span><strong>${escapar(maiorLtv.nome)} · ${moeda(maiorLtv.total)}</strong><p>Cliente com maior valor acumulado na seleção atual.</p></div>
    <div class="finance-insight"><span>Maior honorário atual</span><strong>${escapar(maiorHonorario.nome)} · ${moeda(maiorHonorario.atual)}</strong><p>Maior valor mensal/atual entre os clientes filtrados.</p></div>
    <div class="finance-insight"><span>Evolução média do honorário</span><strong>${crescimentoMedio >= 0 ? "+" : ""}${crescimentoMedio.toFixed(1).replace(".", ",")}%</strong><p>Variação média entre o primeiro honorário e o atual.</p></div>
  `;
}

function empresasDaLista(): EmpresaDaLista[] {
    const palavras = termoDeBusca(obterSecao("financeiro").busca);

    const regime = obterSecao("financeiro").regime;

    return empresas.filter(
        (empresa) =>
            combina(
                `${empresa.codigo ?? ""} ${empresa.nome} ${empresa.documento ?? ""} ${empresa.status} ${empresa.regime ?? ""}`,
                palavras,
            ) && regimeSelecionado(regime, empresa.regime ?? ""),
    );
}

function renderTabela(): void {
    const dados = empresasDaLista();
    porId("financeCount").textContent = plural(dados.length, "empresa", "empresas");

    porId("financeTableBody").innerHTML = dados.length
        ? dados
              .map((empresa) => {
                  const inativa = empresa.status === "Inativo";
                  const documento = empresa.documento
                      ? `<span class="finance-doc-tipo">${escapar(empresa.documentoTipo ?? "CNPJ")}</span><span>${escapar(empresa.documento)}</span>`
                      : "—";

                  return `
            <tr class="${inativa ? "is-inativa" : ""}">
              <td class="finance-codigo">${escapar(empresa.codigo ?? "—")}</td>
              <td><a class="finance-cliente" href="${caminhoDaPagina("cliente", { id: empresa.id })}">${escapar(empresa.nome)}</a></td>
              <td><div class="finance-doc">${documento}</div></td>
              <td><span class="tag ${empresa.statusTag}">${escapar(empresa.status)}</span></td>
            </tr>
          `;
              })
              .join("")
        : `<tr><td colspan="4"><div class="finance-empty">Nenhuma empresa encontrada com os filtros atuais.</div></td></tr>`;
}

function renderFinanceiro(): void {
    const estado = obterSecao("financeiro");
    const dados = linhas();

    porId("financeFilterState").innerHTML = `
    <span class="dot"></span>
    <span>${
        dados.length
            ? `${plural(dados.length, "empresa analisada", "empresas analisadas")}`
            : "Nenhuma empresa encontrada"
    }${estado.busca.trim() ? ` · busca: “${escapar(estado.busca.trim())}”` : ""}${
        regimeDoFiltro(estado.regime) !== "Todos" ? ` · ${regimeDoFiltro(estado.regime)}` : ""
    }</span>
  `;

    renderKpis(dados);
    renderLtv(dados);
    renderDonut(dados);
    renderHonorarios(dados);
    renderConcentracao(dados);
    renderInsights(dados);
    renderTabela();

    if (busca.value !== estado.busca) busca.value = estado.busca;
    regime.value = regimeDoFiltro(estado.regime);
}

busca.addEventListener("input", () =>
    atualizarSecao("financeiro", { busca: busca.value }),
);
regime.addEventListener("change", () =>
    atualizarSecao("financeiro", { regime: regime.value }),
);
porId("clearFinanceFilters").addEventListener("click", () => {
    atualizarSecao("financeiro", { busca: "", regime: "Todos" });
});

observarEstado(["financeiro"], renderFinanceiro);
renderFinanceiro();

/* ---------- Apps Omie (credenciais na base) ---------- */

let omieApps: OmieAppResumo[] = [];
let podeGerenciarOmie = false;
let editandoOmieId: number | null = null;

async function carregarPainelOmie(): Promise<void> {
    const painel = await carregarOmieApps();
    omieApps = painel.apps;
    podeGerenciarOmie = painel.podeGerenciar;
    renderOmieApps();
}

function renderOmieApps(): void {
    const lista = porId("omieAppsLista");
    const form = porId("omieAppsForm") as HTMLFormElement;
    const botaoNovo = porId("omieAppNovo") as HTMLButtonElement;

    botaoNovo.hidden = !podeGerenciarOmie;
    if (!podeGerenciarOmie) {
        form.hidden = true;
        lista.innerHTML =
            `<div class="empty-state">Só administradores cadastram apps Omie. Há ${omieApps.length} app(s) na base.</div>`;
        return;
    }

    if (form.dataset.aberto !== "1") {
        form.hidden = true;
    }

    lista.innerHTML = omieApps.length
        ? omieApps
              .map(
                  (app) => `
        <div class="omie-app-item">
          <div>
            <strong>${escapar(app.nome)}</strong>
            <small>${escapar(app.appKey)} · ${app.ativo ? "Ativo" : "Inativo"}</small>
          </div>
          <div class="omie-app-item-acoes">
            <button type="button" class="secondary" data-omie-editar="${app.id}">Editar</button>
            <button type="button" class="secondary" data-omie-excluir="${app.id}">Excluir</button>
          </div>
        </div>`,
              )
              .join("")
        : `<div class="empty-state">Nenhum app Omie cadastrado ainda.</div>`;

    todos("[data-omie-editar]", lista).forEach((botao) => {
        botao.addEventListener("click", () => {
            const app = omieApps.find((item) => item.id === Number(botao.getAttribute("data-omie-editar")));
            if (!app) return;
            editandoOmieId = app.id;
            form.dataset.aberto = "1";
            campo("omieAppNome").value = app.nome;
            campo("omieAppKey").value = app.appKey;
            campo("omieAppSecret").value = "";
            campo("omieAppSecret").placeholder = "Deixe em branco para manter o secret atual";
            form.hidden = false;
            porId("omieAppsStatus").textContent = `Editando ${app.nome}.`;
        });
    });

    todos("[data-omie-excluir]", lista).forEach((botao) => {
        botao.addEventListener("click", async () => {
            const id = Number(botao.getAttribute("data-omie-excluir"));
            if (!window.confirm("Excluir este app Omie? Empresas vinculadas perdem o vínculo.")) {
                return;
            }
            try {
                await excluirOmieApp(id);
                await carregarPainelOmie();
                porId("omieAppsStatus").textContent = "App removido.";
            } catch (erro) {
                porId("omieAppsStatus").textContent =
                    erro instanceof ErroDeApi ? erro.message : "Não foi possível excluir.";
            }
        });
    });
}

porId("omieAppNovo").addEventListener("click", () => {
    editandoOmieId = null;
    const form = porId("omieAppsForm") as HTMLFormElement;
    form.dataset.aberto = "1";
    form.hidden = false;
    campo("omieAppNome").value = "";
    campo("omieAppKey").value = "";
    campo("omieAppSecret").value = "";
    campo("omieAppSecret").placeholder = "App secret";
    campo("omieAppSecret").required = true;
    porId("omieAppsStatus").textContent = "";
});

porId("omieAppCancelar").addEventListener("click", () => {
    editandoOmieId = null;
    const form = porId("omieAppsForm") as HTMLFormElement;
    form.dataset.aberto = "0";
    form.hidden = true;
    campo("omieAppSecret").required = false;
    porId("omieAppsStatus").textContent = "";
});

porId("omieAppsForm").addEventListener("submit", async (evento) => {
    evento.preventDefault();
    const status = porId("omieAppsStatus");
    const secret = campo("omieAppSecret").value.trim();

    if (!editandoOmieId && secret === "") {
        status.textContent = "Informe o app secret.";
        return;
    }

    try {
        await salvarOmieApp({
            id: editandoOmieId ?? undefined,
            nome: campo("omieAppNome").value.trim(),
            appKey: campo("omieAppKey").value.trim(),
            appSecret: secret || undefined,
            ativo: true,
        });
        editandoOmieId = null;
        const form = porId("omieAppsForm") as HTMLFormElement;
        form.dataset.aberto = "0";
        form.hidden = true;
        campo("omieAppSecret").required = false;
        status.textContent = "App Omie salvo.";
        await carregarPainelOmie();
    } catch (erro) {
        status.textContent =
            erro instanceof ErroDeApi ? erro.message : "Não foi possível salvar o app.";
    }
});

await carregarPainelOmie();
