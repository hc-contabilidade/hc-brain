@extends('layouts.app', [
    'tela' => 'marketing',
    'titulo' => 'Marketing',
    'descricao' => 'Os resultados e conteúdos dos canais da Health Care em um só lugar.',
])

@section('conteudo')
  <section class="page marketing-page active" id="marketing">
    <div class="mkt-row">
      <div>
        <p class="mkt-eyebrow">Marketing</p>
        <h1 class="mkt-title">Redes HC</h1>
        <p class="mkt-lead">Os resultados e conteúdos dos canais da Health Care em um só lugar.</p>
      </div>
      <div class="mkt-row-start">
        <a id="marketingLinkYoutube" href="https://youtube.com/@healthcarecontabilidade/videos" target="_blank" rel="noopener noreferrer" class="mkt-external">
          <i data-lucide="video" class="mkt-icon" aria-hidden="true"></i>YouTube
          <i data-lucide="external-link" class="mkt-icon" aria-hidden="true"></i>
        </a>
        <a id="marketingLinkInstagram" href="https://instagram.com/healthcarecontabilidade/" target="_blank" rel="noopener noreferrer" class="mkt-external">
          <i data-lucide="image" class="mkt-icon" aria-hidden="true"></i>Instagram
          <i data-lucide="external-link" class="mkt-icon" aria-hidden="true"></i>
        </a>
      </div>
    </div>

    <section class="mkt-panel mkt-period" aria-label="Filtros do painel">
      <div class="mkt-row-start">
        <label class="mkt-row-start" for="marketingMonthFilter">
          <i data-lucide="calendar" class="mkt-icon" aria-hidden="true"></i>
          <span class="mkt-field-label">Período</span>
        </label>
        <select id="marketingMonthFilter" class="mkt-select mkt-period-select" aria-label="Período"></select>
        <span id="marketingPeriodStatus"></span>
      </div>
      <div class="mkt-row-start">
        <div class="mkt-row-start">
          <span class="mkt-field-label">Histórico</span>
          <div class="mkt-segmented" role="group" aria-label="Janela do gráfico">
            <button type="button" data-window="3" aria-pressed="false">3 meses</button>
            <button type="button" data-window="6" aria-pressed="true">6 meses</button>
          </div>
        </div>
        <label class="mkt-compare">
          <input id="marketingCompare" type="checkbox" checked>
          Comparar com mês anterior
        </label>
      </div>
    </section>
    <p class="mkt-period-note" id="marketingPeriodNote" role="status"></p>

    <section class="mkt-channel-grid" aria-label="Resultados por canal">
      <article class="mkt-panel mkt-channel" aria-labelledby="yt-title">
        <header class="mkt-channel-head">
          <div class="mkt-channel-brand">
            <span class="mkt-channel-logo youtube"><i data-lucide="video" class="mkt-icon" aria-hidden="true"></i></span>
            <div>
              <h2 id="yt-title">YouTube</h2>
              <p class="mkt-handle">@healthcarecontabilidade</p>
            </div>
          </div>
          <a id="marketingCanalYoutube" href="https://youtube.com/@healthcarecontabilidade/videos" class="mkt-external" target="_blank" rel="noopener noreferrer" aria-label="Abrir canal no YouTube">
            Ver canal <i data-lucide="external-link" class="mkt-icon" aria-hidden="true"></i>
          </a>
        </header>
        <div class="mkt-primary-metrics" id="yt-primary"></div>
        <div class="mkt-secondary-metrics" id="yt-secondary"></div>
        <section class="mkt-chart-section" aria-labelledby="yt-chart-title">
          <div class="mkt-chart-title">
            <h3 id="yt-chart-title">Evolução das visualizações</h3>
            <span class="mkt-chart-legend">
              <span><i class="mkt-legend-mark" style="background:var(--mkt-yt)"></i>Visualizações</span>
            </span>
          </div>
          <div class="mkt-chart-wrap" id="yt-chart">
            <canvas aria-hidden="true"></canvas>
            <div class="mkt-chart-points" aria-label="Meses do gráfico do YouTube"></div>
            <div class="mkt-chart-tooltip" role="status" hidden></div>
          </div>
        </section>
        <footer class="mkt-channel-foot">
          <span id="yt-history-label"></span>
          <button class="mkt-text-action" type="button" data-data="yt">
            <i data-lucide="table-2" class="mkt-icon" aria-hidden="true"></i>Ver dados
          </button>
        </footer>
      </article>

      <article class="mkt-panel mkt-channel" aria-labelledby="ig-title">
        <header class="mkt-channel-head">
          <div class="mkt-channel-brand">
            <span class="mkt-channel-logo instagram"><i data-lucide="image" class="mkt-icon" aria-hidden="true"></i></span>
            <div>
              <h2 id="ig-title">Instagram</h2>
              <p class="mkt-handle">@healthcarecontabilidade</p>
            </div>
          </div>
          <a id="marketingCanalInstagram" href="https://instagram.com/healthcarecontabilidade/" class="mkt-external" target="_blank" rel="noopener noreferrer" aria-label="Abrir perfil no Instagram">
            Ver perfil <i data-lucide="external-link" class="mkt-icon" aria-hidden="true"></i>
          </a>
        </header>
        <div class="mkt-primary-metrics" id="ig-primary"></div>
        <div class="mkt-secondary-metrics" id="ig-secondary"></div>
        <section class="mkt-chart-section" aria-labelledby="ig-chart-title">
          <div class="mkt-chart-title">
            <h3 id="ig-chart-title">Visualizações e alcance</h3>
            <span class="mkt-chart-legend">
              <span><i class="mkt-legend-mark" style="background:var(--mkt-ig)"></i>Visualizações</span>
              <span><i class="mkt-legend-line" style="background:var(--mkt-teal);color:var(--mkt-teal)"></i>Alcance</span>
            </span>
          </div>
          <div class="mkt-chart-wrap" id="ig-chart">
            <canvas aria-hidden="true"></canvas>
            <div class="mkt-chart-points" aria-label="Meses do gráfico do Instagram"></div>
            <div class="mkt-chart-tooltip" role="status" hidden></div>
          </div>
        </section>
        <footer class="mkt-channel-foot">
          <span id="ig-history-label"></span>
          <button class="mkt-text-action" type="button" data-data="ig">
            <i data-lucide="table-2" class="mkt-icon" aria-hidden="true"></i>Ver dados
          </button>
        </footer>
      </article>
    </section>

    <section class="mkt-panel mkt-section" aria-labelledby="content-title">
      <header class="mkt-section-header">
        <div>
          <h2 id="content-title">Conteúdos em destaque</h2>
          <p>Ranking dos conteúdos vinculados ao mês selecionado.</p>
        </div>
        <div class="mkt-row-start">
          <div class="mkt-segmented" role="group" aria-label="Canal dos conteúdos">
            <button type="button" data-content-channel="all" aria-pressed="true">Todos</button>
            <button type="button" data-content-channel="yt" aria-pressed="false">
              <i data-lucide="video" class="mkt-icon" aria-hidden="true"></i>YouTube
            </button>
            <button type="button" data-content-channel="ig" aria-pressed="false">
              <i data-lucide="image" class="mkt-icon" aria-hidden="true"></i>Instagram
            </button>
          </div>
          <div class="mkt-segmented" role="group" aria-label="Formato do YouTube">
            <button type="button" data-content-format="all" aria-pressed="true">Todos</button>
            <button type="button" data-content-format="video" aria-pressed="false">Vídeos</button>
            <button type="button" data-content-format="short" aria-pressed="false">Shorts</button>
          </div>
          <label class="mkt-row-start">
            <span class="mkt-field-label">Ordenar</span>
            <select id="marketingContentSort" class="mkt-select">
              <option value="views">Visualizações</option>
              <option value="likes">Curtidas</option>
              <option value="engagement">Engajamento</option>
            </select>
          </label>
        </div>
      </header>
      <div class="mkt-table-wrap">
        <table class="mkt-table mkt-full-table" aria-labelledby="content-title">
          <thead>
            <tr>
              <th style="width:44px">Pos.</th>
              <th>Conteúdo</th>
              <th style="width:88px">Formato</th>
              <th class="number">Visualizações</th>
              <th class="number">Curtidas</th>
              <th class="number">Comentários</th>
              <th class="number">
                Engajamento
                <button type="button" data-criteria aria-label="Ver cálculo de engajamento" class="mkt-text-action">
                  <i data-lucide="circle-help" class="mkt-icon" aria-hidden="true"></i>
                </button>
              </th>
              <th style="width:48px"></th>
            </tr>
          </thead>
          <tbody id="marketingContents"></tbody>
        </table>
      </div>
      <footer class="mkt-table-footer">
        <span id="marketingContentCount" role="status"></span>
        <span>Abra o conteúdo para consultar a publicação original.</span>
      </footer>
    </section>

    <section class="mkt-panel mkt-section" aria-labelledby="ideas-title">
      <header class="mkt-section-header">
        <div class="mkt-row-start" style="align-items:flex-start">
          <i data-lucide="lightbulb" class="mkt-icon" style="color:var(--mkt-brand);margin-top:2px" aria-hidden="true"></i>
          <div>
            <div class="mkt-row-start">
              <h2 id="ideas-title">Ideias armazenadas</h2>
              <span class="mkt-pill" id="marketingIdeasTotal">0 registros</span>
            </div>
            <p>Consulte as ideias, os responsáveis e o status recebido na base.</p>
          </div>
        </div>
        <div class="mkt-ideas-toolbar">
          <label class="mkt-search-box">
            <i data-lucide="search" class="mkt-icon" aria-hidden="true"></i>
            <input class="mkt-search-input" id="marketingIdeaSearch" type="search" placeholder="Pesquisar ideia..." aria-label="Pesquisar ideia">
          </label>
          <select id="marketingIdeaChannel" class="mkt-select" aria-label="Canal das ideias">
            <option value="">Todos os canais</option>
            <option value="youtube">YouTube</option>
            <option value="instagram">Instagram</option>
            <option value="ambos">Ambos</option>
          </select>
          <select id="marketingIdeaStatus" class="mkt-select" aria-label="Status das ideias">
            <option value="">Todos os status</option>
            <option value="aprovada">Aprovada</option>
            <option value="rascunho">Rascunho</option>
            <option value="publicada">Publicada</option>
            <option value="arquivada">Arquivada</option>
          </select>
        </div>
      </header>
      <div class="mkt-table-wrap">
        <table class="mkt-table mkt-idea-table" aria-labelledby="ideas-title">
          <thead>
            <tr>
              <th>Ideia</th>
              <th>Canal</th>
              <th>Responsável</th>
              <th>Status</th>
              <th style="width:48px"></th>
            </tr>
          </thead>
          <tbody id="marketingIdeas"></tbody>
        </table>
      </div>
      <footer class="mkt-table-footer">
        <span id="marketingIdeaCount" role="status"></span>
        <button class="mkt-text-action" id="marketingIdeaClear" type="button">Limpar filtros</button>
      </footer>
    </section>

    <footer class="mkt-footer">
      <span class="mkt-row-start">
        <i data-lucide="circle-help" class="mkt-icon" aria-hidden="true"></i>
        <span id="marketingFooterNote">Dados das fontes integradas ao HC Brain</span>
      </span>
      <button class="mkt-text-action" type="button" data-criteria>
        Critérios dos indicadores
        <i data-lucide="chevron-right" class="mkt-icon" aria-hidden="true"></i>
      </button>
    </footer>
  </section>

  <dialog id="marketingCriteriaDialog" class="marketing-dialog" aria-labelledby="marketingCriteriaTitle">
    <header class="mkt-dialog-header">
      <div>
        <p class="mkt-eyebrow" style="margin-bottom:4px">Indicadores</p>
        <h2 id="marketingCriteriaTitle">Como ler os resultados</h2>
      </div>
      <button class="mkt-icon-btn" type="button" data-close="marketingCriteriaDialog" aria-label="Fechar critérios">
        <i data-lucide="x" class="mkt-icon" aria-hidden="true"></i>
      </button>
    </header>
    <dl class="mkt-dialog-body">
      <div class="mkt-criteria-row">
        <dt>Visualizações</dt>
        <dd>Contagem de visualizações registrada para cada rede no mês selecionado. Uma pessoa pode gerar mais de uma visualização.</dd>
      </div>
      <div class="mkt-criteria-row">
        <dt>Alcance do Instagram</dt>
        <dd>Quantidade de contas alcançadas no período. No gráfico, alcance e visualizações compartilham a mesma escala.</dd>
      </div>
      <div class="mkt-criteria-row">
        <dt>Engajamento</dt>
        <dd>Usa (curtidas + comentários) ÷ visualizações × 100. Compartilhamentos aparecem em um indicador próprio.</dd>
      </div>
      <div class="mkt-criteria-row">
        <dt>Seguidores e inscritos</dt>
        <dd>Representam o total registrado no mês. A variação indica a diferença absoluta em relação ao mês anterior.</dd>
      </div>
      <div class="mkt-criteria-row">
        <dt>Comparação entre meses</dt>
        <dd>A variação de visualizações usa o mês anterior como base. Meses em andamento mostram dados parciais até a última sincronização.</dd>
      </div>
    </dl>
  </dialog>

  <dialog id="marketingDataDialog" class="marketing-dialog marketing-data-dialog" aria-labelledby="marketingDataTitle">
    <header class="mkt-dialog-header">
      <div>
        <p class="mkt-eyebrow" style="margin-bottom:4px">Histórico</p>
        <h2 id="marketingDataTitle">Dados do gráfico</h2>
      </div>
      <button class="mkt-icon-btn" type="button" data-close="marketingDataDialog" aria-label="Fechar dados">
        <i data-lucide="x" class="mkt-icon" aria-hidden="true"></i>
      </button>
    </header>
    <div id="marketingDataBody" class="mkt-dialog-body"></div>
  </dialog>

  <dialog id="marketingIdeaDialog" class="marketing-dialog" aria-labelledby="marketingIdeaTitle">
    <header class="mkt-dialog-header">
      <h2 id="marketingIdeaTitle"></h2>
      <button class="mkt-icon-btn" type="button" data-close="marketingIdeaDialog" aria-label="Fechar ideia">
        <i data-lucide="x" class="mkt-icon" aria-hidden="true"></i>
      </button>
    </header>
    <div id="marketingIdeaBody" class="mkt-dialog-body"></div>
  </dialog>
@endsection
