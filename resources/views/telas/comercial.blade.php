@extends('layouts.app', [
    'tela' => 'comercial',
    'titulo' => 'Comercial',
    'descricao' => 'Painel comercial da HC: leads, reuniões, vendas fechadas e motivos de perda.',
])

@section('conteudo')
  {{-- Painel gerencial espelhando o Painel de Vendas do Automacao. --}}
  <section class="page commercial-page active" id="comercial">
    <div class="commercial-head">
      <div>
        <span class="page-overline">GESTÃO COMERCIAL • VISÃO GERENCIAL</span>
        <h1>Painel Comercial</h1>
        <p class="muted">
          Indicadores do CRM Automacao: leads, reuniões, vendas (abertura e transferência)
          e motivos de perda.
        </p>
      </div>
      <div class="commercial-period">
        <label for="commercialMonthFilter">Período</label>
        {{-- As opções são os meses que existem na base; o script as monta. --}}
        <select id="commercialMonthFilter"></select>
      </div>
    </div>

    <div class="commercial-kpis" id="commercialKpis"></div>

    <div class="commercial-status-row">
      <div class="commercial-pipeline-card">
        <div class="commercial-card-head">
          <div>
            <h2>Funil no período</h2>
            <p>Distribuição dos leads pelas etapas do CRM.</p>
          </div>
          <span class="commercial-badge">mês selecionado</span>
        </div>
        <div class="commercial-funnel" id="commercialFunnel"></div>
      </div>

      <div class="commercial-performance-card">
        <div class="commercial-card-head">
          <div>
            <h2>Indicadores de conversão</h2>
            <p>Principais taxas para leitura rápida da operação.</p>
          </div>
        </div>
        <div class="commercial-rates" id="commercialRates"></div>
      </div>
    </div>

    <div class="commercial-grid-2">
      <div class="commercial-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Leads recebidos no mês</h2>
            <p>Volume de entradas e evolução dos últimos meses.</p>
          </div>
        </div>
        <div id="commercialMonthlyLeads"></div>
      </div>

      <div class="commercial-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Origem dos leads</h2>
            <p>Orgânico, ativo ou pago.</p>
          </div>
        </div>
        <div class="commercial-origin-layout">
          <div class="commercial-donut" id="commercialOriginDonut">
            <div class="commercial-donut-center">
              <strong id="commercialOriginTotal">0</strong>
              <span>leads</span>
            </div>
          </div>
          <div class="commercial-legend" id="commercialOriginLegend"></div>
        </div>
      </div>
    </div>

    <div class="commercial-grid-2">
      <div class="commercial-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Fonte dos leads</h2>
            <p>Indicação, redes sociais, evento e demais canais de aquisição.</p>
          </div>
        </div>
        <div class="commercial-legend" id="commercialSourceLegend"></div>
      </div>

      <div class="commercial-panel loss-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Ranking de motivos de perda</h2>
            <p>Leads que não fecharam e os motivos registrados pelo comercial.</p>
          </div>
          <span class="commercial-total-pill" id="commercialLostTotal">0 perdas</span>
        </div>
        <div class="commercial-loss-list" id="commercialLossReasons"></div>
      </div>
    </div>

    <div class="commercial-grid-2">
      <div class="commercial-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Vendas fechadas</h2>
            <p>Fechamentos do período por abertura ou transferência.</p>
          </div>
        </div>
        <div class="commercial-sales-list" id="commercialSalesList"></div>
      </div>

      <div class="commercial-panel commercial-team-panel">
        <div class="commercial-card-head">
          <div>
            <h2>Desempenho do time</h2>
            <p>Ranking por quantidade de vendas fechadas no período.</p>
          </div>
        </div>
        <div class="commercial-team-table-wrap">
          <table class="commercial-team-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Responsável</th>
                <th>Leads</th>
                <th>Vendas</th>
                <th>Conversão</th>
              </tr>
            </thead>
            <tbody id="commercialTeamBody">
              <tr>
                <td colspan="5">
                  <div class="estado-carregando">Carregando período…</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
@endsection
