@extends('layouts.app', [
    'tela' => 'marketing',
    'titulo' => 'Marketing',
    'descricao' => 'Ideias de vídeo e métricas de YouTube e Instagram.',
])

@section('conteudo')
  <section class="page marketing-page active" id="marketing">
    <div class="marketing-head">
      <div>
        <span class="page-overline">MARKETING</span>
        <h1>Redes HC</h1>
      </div>
      <div class="marketing-head-actions">
        <div class="marketing-canais" id="marketingCanais"></div>
        <div class="marketing-period">
          <label for="marketingMonthFilter">Período</label>
          <select id="marketingMonthFilter"></select>
        </div>
      </div>
    </div>

    <div class="marketing-snapshot" id="marketingSnapshot"></div>

    <div class="marketing-platforms">
      <article class="marketing-panel">
        <div class="marketing-card-head">
          <h2>YouTube</h2>
          <a class="marketing-canal-link" id="marketingLinkYoutube" href="https://youtube.com/@healthcarecontabilidade/videos" target="_blank" rel="noopener noreferrer">Canal</a>
        </div>
        <div class="marketing-statline" id="marketingYoutubeStats"></div>
        <div id="marketingYoutubeChart"></div>
        <div id="marketingYoutubeInsight" class="marketing-insight"></div>
      </article>

      <article class="marketing-panel">
        <div class="marketing-card-head">
          <h2>Instagram</h2>
          <a class="marketing-canal-link" id="marketingLinkInstagram" href="https://instagram.com/healthcarecontabilidade/" target="_blank" rel="noopener noreferrer">Perfil</a>
        </div>
        <div class="marketing-statline" id="marketingInstagramStats"></div>
        <div id="marketingInstagramChart"></div>
        <div id="marketingInstagramInsight" class="marketing-insight"></div>
      </article>
    </div>

    <div class="marketing-panel marketing-ideas-panel">
      <div class="marketing-card-head">
        <h2>Ideias</h2>
        <span class="marketing-card-meta" id="marketingIdeasCount"></span>
      </div>
      <div class="marketing-ideas" id="marketingIdeas"></div>
    </div>
  </section>
@endsection
