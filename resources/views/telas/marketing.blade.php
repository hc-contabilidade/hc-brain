@extends('layouts.app', [
    'tela' => 'marketing',
    'titulo' => 'Marketing',
    'descricao' => 'Ideias de vídeo e métricas de YouTube e Instagram.',
])

@section('conteudo')
  <section class="page marketing-page active" id="marketing">
    <div class="marketing-head">
      <div>
        <span class="page-overline">MARKETING • RASCUNHO</span>
        <h1>Painel Marketing</h1>
        <p class="muted">
          Ideias para vídeo e leitura rápida dos gráficos de YouTube e Instagram.
          Métricas reais entram quando a sync das redes estiver configurada.
        </p>
      </div>
      <div class="marketing-period">
        <label for="marketingMonthFilter">Período</label>
        <select id="marketingMonthFilter"></select>
      </div>
    </div>

    <div class="marketing-kpis" id="marketingKpis"></div>

    <div class="marketing-grid-2">
      <div class="marketing-panel">
        <div class="marketing-card-head">
          <div>
            <h2>YouTube — views</h2>
            <p>Evolução dos últimos meses.</p>
          </div>
        </div>
        <div id="marketingYoutubeChart"></div>
      </div>
      <div class="marketing-panel">
        <div class="marketing-card-head">
          <div>
            <h2>Instagram — views</h2>
            <p>Evolução dos últimos meses.</p>
          </div>
        </div>
        <div id="marketingInstagramChart"></div>
      </div>
    </div>

    <div class="marketing-panel marketing-ideas-panel">
      <div class="marketing-card-head">
        <div>
          <h2>Ideias para vídeo</h2>
          <p>Lista de rascunhos e ideias aprovadas para acompanhamento.</p>
        </div>
      </div>
      <div class="marketing-ideas" id="marketingIdeas"></div>
    </div>
  </section>
@endsection
