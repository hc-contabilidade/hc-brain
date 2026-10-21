/* =========================================================
   TELA DETALHE DA EMPRESA
   Reúne, em uma página só desta empresa, o sócio responsável,
   as fontes, as reuniões, os treinamentos relacionados e o
   financeiro — tudo em uma resposta só da API.

   Quando o id do endereço não existe, o Blade já devolveu o
   estado de erro e não há nada para carregar aqui.
   ========================================================= */

import {
    carregarEmpresa,
    type EmpresaDetalhada,
    type FinanceiroEmpresa,
} from "../dados/empresas.ts";
import { tagDoStatus, tagDoTipoDeReuniao, type Reuniao } from "../dados/reunioes.ts";
import { aparenciaPorTipo, type ConteudoTreinamento } from "../dados/treinamentos.ts";
import { dado, porId, porSeletor, talvez, todos } from "../comum/dom.ts";
import { atualizarSecao } from "../comum/estado.ts";
import { duracaoEmMeses, escapar, iniciais, moeda } from "../comum/formato.ts";
import { desenharIcones, icone } from "../comum/icones.ts";
import { openMeetingModal, showModal } from "../comum/modal.ts";
import { caminhoDaPagina } from "../comum/paginas.ts";
import { iniciarPagina } from "../comum/shell.ts";

iniciarPagina("cliente");

const detalhe = talvez("#empresaDetailView");

if (detalhe) {
    const { empresa, reunioes, treinamentos, financeiro } = await carregarEmpresa(
        dado(detalhe, "empresaId"),
    );

    detalhe.classList.add("active");
    renderCabecalho(empresa, reunioes);
    renderSocio(empresa);
    renderFontes(empresa);
    renderReunioes(reunioes);
    renderTreinamentos(empresa, treinamentos);
    renderFinanceiro(empresa, reunioes.length, financeiro);
    desenharIcones();

    // O atalho para Reuniões já leva o filtro da empresa aplicado.
    porId("verTodasReunioesEmpresa").addEventListener("click", () => {
        atualizarSecao("reunioes", { empresa: empresa.nome });
        window.location.href = caminhoDaPagina("reunioes");
    });
}

function renderCabecalho(empresa: EmpresaDetalhada, reunioes: Reuniao[]): void {
    const ultima = reunioes[0];

    porId("detailAvatar").textContent = iniciais(empresa.nome);
    porId("detailNome").textContent = empresa.nome;
    porId("detailSetor").textContent = empresa.setor;

    const status = porId("detailStatus");
    status.textContent = empresa.status;
    status.className = `tag ${empresa.statusTag}`;

    porId("detailReunioesCount").textContent = String(reunioes.length);
    porId("detailFontesCount").textContent = String(empresa.fontes.length);
    porId("detailUltimaReuniao").textContent = ultima ? ultima.data : "—";
}

function renderSocio(empresa: EmpresaDetalhada): void {
    porId("socioCard").innerHTML = `
    <div class="socio-header">
      <div class="socio-avatar">${iniciais(empresa.socio.nome)}</div>
      <div>
        <strong>${escapar(empresa.socio.nome)}</strong>
        <span>${escapar(empresa.socio.cargo)}</span>
      </div>
    </div>
    <div class="socio-field"><span>E-mail</span><span>${escapar(empresa.socio.email)}</span></div>
    <div class="socio-field"><span>Telefone</span><span>${escapar(empresa.socio.telefone)}</span></div>
    <div class="socio-field"><span>Participação</span><span>${escapar(empresa.socio.participacao)}</span></div>
    <div class="socio-field"><span>Relacionamento</span><span>${escapar(empresa.socio.desde)}</span></div>
  `;
}

function renderFontes(empresa: EmpresaDetalhada): void {
    const fontes = porId("fontesList");

    fontes.innerHTML = empresa.fontes.length
        ? empresa.fontes
              .map(
                  (fonte) => `
            <button type="button" class="fonte-item" data-fonte="${escapar(fonte.nome)}" data-fonte-info="${escapar(`${fonte.tipo} · ${fonte.info}`)}">
              <span class="fonte-icon">${escapar(fonte.tipo)}</span>
              <span class="fonte-texto">
                <strong>${escapar(fonte.nome)}</strong>
                <small>${escapar(fonte.info)}</small>
              </span>
            </button>
          `,
              )
              .join("")
        : `<div class="empty-state">Nenhuma fonte vinculada diretamente a esta empresa.</div>`;

    // A fonte é a referência que a HC guarda do material da empresa, não o
    // arquivo em si — quem guarda arquivo é a tela de Documentos.
    todos("[data-fonte]", fontes).forEach((item) => {
        item.addEventListener("click", () => {
            showModal(
                dado(item, "fonte"),
                `${dado(item, "fonteInfo")} — referência vinculada à empresa ${empresa.nome}.`,
            );
        });
    });
}

function renderReunioes(reunioes: Reuniao[]): void {
    const listaReunioes = porId("empresaMeetingsList");

    listaReunioes.innerHTML = reunioes.length
        ? reunioes
              .map(
                  (reuniao) => `
            <button type="button" class="mini-meeting" data-meeting-id="${reuniao.id}">
              <span class="tag ${tagDoTipoDeReuniao[reuniao.tipo] ?? ""}">${escapar(reuniao.tipo)}</span>
              <span class="mini-meeting-body">
                <strong>Reunião de ${escapar(reuniao.tipo)} — ${reuniao.data}</strong>
                <small>${escapar(reuniao.resumo)}</small>
              </span>
              <span class="tag ${tagDoStatus[reuniao.status] ?? ""}">${escapar(reuniao.status)}</span>
            </button>
          `,
              )
              .join("")
        : `<div class="empty-state">Nenhuma reunião vinculada a esta empresa ainda.</div>`;

    todos("[data-meeting-id]", listaReunioes).forEach((item) => {
        item.addEventListener("click", () => {
            const reuniao = reunioes.find(
                (registro) => registro.id === Number(dado(item, "meetingId")),
            );
            if (reuniao) openMeetingModal(reuniao);
        });
    });
}

function renderTreinamentos(empresa: EmpresaDetalhada, treinamentos: ConteudoTreinamento[]): void {
    const listaTreinamentos = porId("empresaTreinamentosList");

    listaTreinamentos.innerHTML = treinamentos.length
        ? treinamentos
              .map((conteudo) => {
                  const aparencia = aparenciaPorTipo[conteudo.tipo];
                  const descricao = `${conteudo.tipo} • ${conteudo.categoria}${conteudo.processo ? ` • ${conteudo.processo}` : ""}`;
                  return `
            <button type="button" class="empresa-treinamento-item" data-treinamento="${escapar(conteudo.id)}">
              <span class="icone-quadro ${aparencia.cor}">${icone(aparencia.icone)}</span>
              <span class="empresa-treinamento-texto">
                <strong>${escapar(conteudo.titulo)}</strong>
                <small>${escapar(descricao)}</small>
              </span>
            </button>
          `;
              })
              .join("")
        : `<div class="empty-state">Nenhum treinamento relacionado a esta empresa.</div>`;

    todos("[data-treinamento]", listaTreinamentos).forEach((item) => {
        item.addEventListener("click", () => {
            const conteudo = treinamentos.find(
                (registro) => registro.id === dado(item, "treinamento"),
            );
            if (conteudo) {
                showModal(
                    conteudo.titulo,
                    `${conteudo.descricao}\n\nEste conteúdo foi relacionado à ${empresa.nome} porque atende a um processo ou necessidade associada ao cliente.`,
                );
            }
        });
    });
}

function renderFinanceiro(
    empresa: EmpresaDetalhada,
    totalReunioes: number,
    dados: FinanceiroEmpresa | null,
): void {
    if (!dados) {
        porSeletor(".empresa-relacionamento-body").innerHTML =
            `<div class="empty-state">Sem dados financeiros para esta empresa.</div>`;
        porSeletor(".empresa-financeiro").hidden = true;
        return;
    }

    const receitaTotal = dados.receitaMensal.reduce((soma, item) => soma + item.valor, 0);
    const crescimento =
        dados.primeiro > 0 ? ((dados.atual - dados.primeiro) / dados.primeiro) * 100 : 0;

    porId("empresaLtvGrid").innerHTML = `
    <div class="empresa-ltv-card highlight">
      <span>Tempo de vida / LTV</span>
      <strong>${duracaoEmMeses(dados.meses)}</strong>
      <small>Relacionamento com a HC</small>
    </div>
    <div class="empresa-ltv-card">
      <span>Cliente desde</span>
      <strong>${new Date(`${dados.desde}T00:00:00`).toLocaleDateString("pt-BR")}</strong>
      <small>Data exata de entrada</small>
    </div>
    <div class="empresa-ltv-card">
      <span>1º honorário</span>
      <strong>${moeda(dados.primeiro)}</strong>
      <small>Valor inicial mensal</small>
    </div>
    <div class="empresa-ltv-card">
      <span>Honorário atual</span>
      <strong>${moeda(dados.atual)}</strong>
      <small>Valor mensal atual</small>
    </div>
    <div class="empresa-ltv-card">
      <span>Total arrecadado</span>
      <strong>${moeda(dados.total)}</strong>
      <small>Recebido pela HC</small>
    </div>
    <div class="empresa-ltv-card">
      <span>Evolução do honorário</span>
      <strong>${crescimento >= 0 ? "+" : ""}${crescimento.toFixed(1)}%</strong>
      <small>${moeda(dados.primeiro)} → ${moeda(dados.atual)}</small>
    </div>
  `;

    porId("empresaRelacionamentoBase").innerHTML = `
    <div class="empresa-base-item"><span>Documentos</span><strong>${empresa.fontes.length}</strong></div>
    <div class="empresa-base-item"><span>Reuniões</span><strong>${totalReunioes}</strong></div>
    <div class="empresa-base-item"><span>Extratos</span><strong>${dados.receitaMensal.length} períodos</strong></div>
    <div class="empresa-base-item saldo"><span>Saldo atual</span><strong>${moeda(dados.saldo)}</strong></div>
  `;

    const receitaMaxima = Math.max(...dados.receitaMensal.map((item) => item.valor), 1);
    const entradas = dados.transacoes
        .filter((item) => item.tipo === "Entrada")
        .reduce((soma, item) => soma + item.valor, 0);
    const saidas = dados.transacoes
        .filter((item) => item.tipo === "Saída")
        .reduce((soma, item) => soma + item.valor, 0);

    porId("empresaFinanceiroKpis").innerHTML = `
    <div class="empresa-financeiro-kpi"><span>Saldo atual</span><strong>${moeda(dados.saldo)}</strong><small>Posição consolidada</small></div>
    <div class="empresa-financeiro-kpi"><span>A receber</span><strong>${moeda(dados.receber)}</strong><small>Valores previstos</small></div>
    <div class="empresa-financeiro-kpi"><span>Despesas</span><strong>${moeda(dados.despesas)}</strong><small>Período atual</small></div>
    <div class="empresa-financeiro-kpi"><span>Margem estimada</span><strong>${dados.margem}%</strong><small>Receita × despesas</small></div>
  `;

    porId("empresaFinanceiroMonths").innerHTML = dados.receitaMensal
        .map(
            (item) => `
        <div class="empresa-financeiro-month">
          <span class="empresa-financeiro-month-label">${item.mes}</span>
          <div class="barra"><span style="width:${Math.max(2, (item.valor / receitaMaxima) * 100)}%"></span></div>
          <strong class="empresa-financeiro-month-value">${moeda(item.valor)}</strong>
        </div>
      `,
        )
        .join("");

    porId("empresaFinanceiroSummary").innerHTML = `
    <div class="empresa-financeiro-summary-item"><span>Receita últimos 5 meses</span><strong>${moeda(receitaTotal)}</strong></div>
    <div class="empresa-financeiro-summary-item"><span>Honorário atual</span><strong>${moeda(dados.receitaMensal.at(-1)?.valor)}</strong></div>
    <div class="empresa-financeiro-summary-item"><span>Entradas lançadas</span><strong>${moeda(entradas)}</strong></div>
    <div class="empresa-financeiro-summary-item"><span>Saídas lançadas</span><strong>${moeda(saidas)}</strong></div>
  `;

    porId("empresaFinanceiroTransactions").innerHTML = dados.transacoes
        .map((item) => {
            const classeStatus = item.status
                .toLowerCase()
                .normalize("NFD")
                .replace(/[\u0300-\u036f]/g, "")
                .replace(" ", "-");
            return `
        <tr>
          <td>${item.data}</td>
          <td>${escapar(item.desc)}</td>
          <td>${escapar(item.cat)}</td>
          <td>${item.tipo}</td>
          <td>${moeda(item.valor)}</td>
          <td><span class="finance-status ${classeStatus}">${item.status}</span></td>
        </tr>
      `;
        })
        .join("");
}
