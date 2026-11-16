@extends('layouts.app', [
    'tela' => 'financeiro',
    'titulo' => 'Financeiro geral',
    'descricao' => 'Visão consolidada da carteira: LTV, honorários e concentração de receita.',
])

@section('conteudo')
  {{-- Visão financeira consolidada de todas as empresas. --}}
  <section class="page finance-page active" id="financeiro-geral">
    <span class="page-overline">VISÃO FINANCEIRA • TODAS AS EMPRESAS</span>

    <div class="finance-head">
      <div class="finance-head-copy">
        <h1>Financeiro geral</h1>
        <p class="muted">
          Uma visão consolidada da carteira para comparar valor dos clientes,
          honorários e concentração de receita.
        </p>
      </div>
    </div>

    <div class="finance-panel" id="omieAppsPanel" hidden>
      <div class="finance-panel-head">
        <div>
          <h2>Apps Omie</h2>
          <p>Credenciais dos apps Omie da HC. Só administradores veem e alteram.</p>
        </div>
        <button type="button" class="secondary" id="omieAppsToggle" aria-expanded="false" aria-controls="omieAppsCorpo">Mostrar</button>
      </div>
      <div class="finance-panel-body" id="omieAppsCorpo" hidden>
        <div class="omie-apps-lista" id="omieAppsLista"></div>
        <form class="omie-apps-form" id="omieAppsForm" hidden>
          <div class="omie-apps-campos">
            <div>
              <label for="omieAppNome">Nome</label>
              <input id="omieAppNome" required maxlength="120" placeholder="Ex.: HC Contábil" />
            </div>
            <div>
              <label for="omieAppKey">App key</label>
              <input id="omieAppKey" required maxlength="120" autocomplete="off" />
            </div>
            <div>
              <label for="omieAppSecret">App secret</label>
              <input id="omieAppSecret" type="password" maxlength="255" autocomplete="new-password" placeholder="••••••••" />
            </div>
          </div>
          <div class="omie-apps-acoes">
            <button type="submit" class="primary" id="omieAppSalvar">Salvar app</button>
            <button type="button" class="secondary" id="omieAppCancelar">Cancelar</button>
            <p class="muted" id="omieAppsStatus"></p>
          </div>
        </form>
        <button type="button" class="secondary" id="omieAppNovo">Novo app Omie</button>
      </div>
    </div>

    <div class="finance-toolbar">
      <div class="finance-field">
        <label for="financeEmpresaSearch">Empresa</label>
        <input
          id="financeEmpresaSearch"
          placeholder="Pesquisar empresa..."
          autocomplete="off"
        />
      </div>
      <div class="finance-field">
        <label for="financeRegimeFilter">Regime tributário</label>
        <select id="financeRegimeFilter">
          <option value="Todos">Todos os regimes</option>
          <option value="Lucro Presumido">Lucro Presumido</option>
          <option value="MEI">MEI</option>
          <option value="Simples Nacional">Simples Nacional</option>
          <option value="Pessoa Física">Pessoa Física</option>
        </select>
      </div>
      <div class="finance-field">
        <label for="financeOrdem">Ordenar</label>
        <select id="financeOrdem">
          <option value="nome-asc">Nome (A–Z)</option>
          <option value="nome-desc">Nome (Z–A)</option>
          <option value="codigo-asc">Código crescente</option>
          <option value="codigo-desc">Código decrescente</option>
          <option value="ltv-desc">Maior LTV</option>
          <option value="honorario-desc">Maior honorário</option>
        </select>
      </div>
      <button class="finance-clear" id="clearFinanceFilters">Limpar filtros</button>
    </div>

    <div class="finance-filter-state" id="financeFilterState">
      <span class="dot"></span><span>Todos os clientes</span>
    </div>

    <div class="finance-kpis" id="financeKpis">
      <div class="estado-carregando">Carregando…</div>
    </div>

    <div class="finance-main-grid">
      <div class="finance-panel">
        <div class="finance-panel-head">
          <div>
            <h2>LTV médio por regime</h2>
            <p>Quanto cada grupo de empresas já deixou, em média.</p>
          </div>
        </div>
        <div class="finance-panel-body" id="financeLtvChart">
          <div class="estado-carregando">Carregando…</div>
        </div>
      </div>

      <div class="finance-panel">
        <div class="finance-panel-head">
          <div>
            <h2>Distribuição do valor arrecadado</h2>
            <p>Participação de cada regime na carteira filtrada.</p>
          </div>
        </div>
        <div class="finance-panel-body">
          <div class="estado-carregando" id="financeDonutCarregando">Carregando…</div>
          <div class="finance-donut-wrap" id="financeDonutWrap" hidden>
            <div class="finance-donut" id="financeDonut">
              <div class="finance-donut-center">
                <strong id="financeDonutTotal"></strong><span>carteira</span>
              </div>
            </div>
            <div class="finance-legend" id="financeLegend"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="finance-secondary-grid">
      <div class="finance-panel">
        <div class="finance-panel-head">
          <div>
            <h2>Honorário: entrada × atual</h2>
            <p>Comparação entre o primeiro valor e o honorário atual.</p>
          </div>
        </div>
        <div class="finance-panel-body" id="financeHonorariumChart">
          <div class="estado-carregando">Carregando…</div>
        </div>
      </div>

      <div class="finance-panel">
        <div class="finance-panel-head">
          <div>
            <h2>Concentração da carteira</h2>
            <p>Empresas que representam maior valor arrecadado no grupo.</p>
          </div>
        </div>
        <div class="finance-panel-body" id="financeTopClients">
          <div class="estado-carregando">Carregando…</div>
        </div>
      </div>
    </div>

    <div class="finance-insights" id="financeInsights"></div>

    <div class="finance-table-panel">
      <div class="finance-table-head">
        <div>
          <h2>Empresas</h2>
          <span class="muted">Ativas e inativas, como na automação. O status acompanha a sincronização.</span>
        </div>
        <span class="finance-count" id="financeCount" hidden></span>
      </div>
      <div class="finance-table-wrap">
        <table class="finance-table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Cliente</th>
              <th>Status</th>
              <th>CNPJ/CPF</th>
              <th>Regime</th>
              <th>Tempo de vida</th>
              <th>1º honorário</th>
              <th>Honorário atual</th>
              <th>LTV / total arrecadado</th>
            </tr>
          </thead>
          <tbody id="financeTableBody">
            <tr>
              <td colspan="9">
                <div class="estado-carregando">Carregando carteira…</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
@endsection
