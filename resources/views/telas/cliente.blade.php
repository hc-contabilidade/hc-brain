@extends('layouts.app', [
    'tela' => 'cliente',
    'titulo' => $empresa?->nome ?? 'Detalhe da empresa',
    'descricao' => 'Sócio responsável, fontes, reuniões, treinamentos e financeiro de uma empresa da carteira.',
])

@section('conteudo')
  {{-- O detalhe tem URL própria e pode ser compartilhado por link. --}}
  <section class="page active" id="empresa">
    @if ($empresa === null)
      <div class="estado-erro">
        <h2>Empresa não encontrada</h2>
        <p>
          O identificador informado no endereço não corresponde a nenhuma empresa da
          base. Volte para a lista e escolha um cliente.
        </p>
        <a class="back-button" href="{{ route('clientes') }}">
          <i data-lucide="arrow-left"></i>
          Voltar para empresas
        </a>
      </div>
    @else
      <div class="empresa-detail" id="empresaDetailView" data-empresa-id="{{ $empresa->id }}">
        <a class="back-button" href="{{ route('clientes') }}">
          <i data-lucide="arrow-left"></i>
          Voltar para empresas
        </a>

        <div class="empresa-header">
          <div class="empresa-header-left">
            <span class="empresa-avatar large" id="detailAvatar"></span>
            <div>
              <h1 id="detailNome">{{ $empresa->nome }}</h1>
              <div class="empresa-header-tags">
                <span class="tag gray" id="detailSetor"></span>
                <span class="tag green" id="detailStatus"></span>
              </div>
            </div>
          </div>

          <dl class="empresa-quick-stats">
            <div>
              <dt>Reuniões</dt>
              <dd id="detailReunioesCount">0</dd>
            </div>
            <div>
              <dt>Fontes vinculadas</dt>
              <dd id="detailFontesCount">0</dd>
            </div>
            <div>
              <dt>Última reunião</dt>
              <dd id="detailUltimaReuniao">—</dd>
            </div>
          </dl>

        </div>

        <div class="empresa-relacionamento-financeiro">
          <div class="empresa-relacionamento-head">
            <div>
              <h2>Relacionamento financeiro</h2>
              <p>
                Indicadores do relacionamento da empresa com a HC, junto aos principais
                dados do cadastro.
              </p>
            </div>
          </div>
          <div class="empresa-relacionamento-body">
            <div class="empresa-ltv-grid" id="empresaLtvGrid"></div>
            <div class="empresa-relacionamento-base" id="empresaRelacionamentoBase"></div>
          </div>
        </div>

        <div class="empresa-body">
          <div class="socio-card" id="socioCard"></div>

          <div>
            <div class="empresa-section-title">
              <h2>Fontes vinculadas à empresa</h2>
            </div>
            <div class="fontes-list" id="fontesList"></div>

            <div class="empresa-section-title">
              <h2>Reuniões vinculadas</h2>
              <button class="clear-filters" id="verTodasReunioesEmpresa" type="button">
                Ver na página de reuniões
                <i data-lucide="arrow-right"></i>
              </button>
            </div>
            <div id="empresaMeetingsList"></div>
          </div>
        </div>

        <div class="empresa-treinamentos">
          <div class="empresa-treinamentos-head">
            <h2>Conhecimento e treinamento relacionado</h2>
            <p>
              Conteúdos da capacitação associados aos processos e necessidades desta
              empresa.
            </p>
          </div>
          <div class="empresa-treinamentos-list" id="empresaTreinamentosList"></div>
        </div>

        <div class="empresa-financeiro">
          <div class="empresa-financeiro-head">
            <div>
              <h2>Financeiro da empresa</h2>
              <p class="muted">
                Visão detalhada dos valores, recebimentos, despesas e evolução
                financeira deste cliente.
              </p>
            </div>
          </div>
          <div class="empresa-financeiro-kpis" id="empresaFinanceiroKpis"></div>
          <div class="empresa-financeiro-grid">
            <div class="empresa-financeiro-panel">
              <div class="empresa-financeiro-panel-head">
                <h3>Receita por período</h3>
                <p>Valores recebidos ou previstos nos últimos meses.</p>
              </div>
              <div class="empresa-financeiro-panel-body">
                <div class="empresa-financeiro-months" id="empresaFinanceiroMonths"></div>
              </div>
            </div>
            <div class="empresa-financeiro-panel">
              <div class="empresa-financeiro-panel-head">
                <h3>Resumo financeiro</h3>
                <p>Composição atual do relacionamento financeiro.</p>
              </div>
              <div class="empresa-financeiro-panel-body">
                <div class="empresa-financeiro-summary" id="empresaFinanceiroSummary"></div>
              </div>
            </div>
          </div>
          <div class="empresa-financeiro-panel">
            <div class="empresa-financeiro-panel-head">
              <h3>Movimentações financeiras</h3>
              <p>Últimos lançamentos associados à empresa.</p>
            </div>
            <div class="tabela-wrap">
              <table class="tabela">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Tipo</th>
                    <th>Valor</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="empresaFinanceiroTransactions"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    @endif
  </section>
@endsection
