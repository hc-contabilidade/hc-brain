@extends('layouts.app', [
    'tela' => 'marketing',
    'titulo' => 'Marketing',
    'descricao' => 'Ideias de vídeo e métricas de YouTube e Instagram.',
])

@section('conteudo')
  <section class="page marketing-page active" id="marketing">
    <div class="marketing-head">
      <div>
        <span class="page-overline">MARKETING • ACOMPANHAMENTO</span>
        <h1>Painel Marketing</h1>
        <p class="muted">
          Leitura das redes da HC: crescimento, conteúdo em destaque e ideias em fila.
        </p>
        <div class="marketing-canais" id="marketingCanais"></div>
      </div>
      <div class="marketing-period">
        <label for="marketingMonthFilter">Período</label>
        <select id="marketingMonthFilter"></select>
      </div>
    </div>

    <div class="marketing-kpis" id="marketingKpis"></div>

    <div class="marketing-platforms">
      <article class="marketing-panel marketing-panel-yt">
        <div class="marketing-card-head">
          <div>
            <h2>YouTube</h2>
            <p>Views, inscritos novos e o vídeo que mais puxou o canal.</p>
          </div>
          <a class="marketing-canal-link" id="marketingLinkYoutube" href="https://youtube.com/@healthcarecontabilidade/videos" target="_blank" rel="noopener noreferrer">Abrir canal</a>
        </div>
        <div id="marketingYoutubeChart"></div>
        <div id="marketingYoutubeInsight" class="marketing-insight"></div>
      </article>

      <article class="marketing-panel marketing-panel-ig">
        <div class="marketing-card-head">
          <div>
            <h2>Instagram</h2>
            <p>Views, alcance e evolução de seguidores.</p>
          </div>
          <a class="marketing-canal-link" id="marketingLinkInstagram" href="https://instagram.com/healthcarecontabilidade/" target="_blank" rel="noopener noreferrer">Abrir perfil</a>
        </div>
        <div id="marketingInstagramChart"></div>
        <div id="marketingInstagramInsight" class="marketing-insight"></div>
      </article>
    </div>

    <div class="marketing-panel marketing-ideas-panel">
      <div class="marketing-card-head">
        <div>
          <h2>Ideias para vídeo</h2>
          <p>Fila de rascunhos e ideias aprovadas — só consulta.</p>
        </div>
      </div>
      <div class="marketing-ideas" id="marketingIdeas"></div>
    </div>
  </section>
@endsection
