@extends('layouts.app', [
    'tela' => 'sofia-ia',
    'titulo' => 'Sofia IA',
    'descricao' => 'Converse com a Sofia e pesquise na base de conhecimento da HC.',
])

@section('conteudo')
  {{-- Interface da assistente, no formato de um chat de IA. --}}
  <section class="page page-cheia active" id="sofia">
    <div
      class="sofia-ai-screen"
      id="sofiaTela"
      data-transcricao-servidor="{{ $transcricaoConfigurada ? '1' : '0' }}"
    >
      <button
        type="button"
        class="sofia-historico-fundo"
        id="sofiaHistoricoFundo"
        aria-label="Fechar lista de chats"
        hidden
      ></button>

      <aside class="sofia-historico" id="sofiaHistorico" aria-label="Chats com a Sofia">
        <div class="sofia-historico-topo">
          <button type="button" class="icon-button" id="sofiaBuscaBtn" aria-label="Buscar chats" title="Buscar chats">
            <i data-lucide="search"></i>
          </button>
        </div>

        <button type="button" class="sofia-nova-conversa" id="sofiaNovaConversa">
          <i data-lucide="plus"></i>
          <span>Novo chat</span>
        </button>

        <label class="sofia-busca" id="sofiaBuscaWrap" hidden>
          <span class="sr-only">Buscar chats</span>
          <input id="sofiaBuscaChats" type="search" placeholder="Buscar em chats" autocomplete="off" />
        </label>

        <p class="sofia-historico-rotulo">Recentes</p>
        <div class="sofia-conversas" id="sofiaConversas"></div>

        <div class="sofia-usuario">
          <span class="avatar sofia-usuario-avatar" id="sofiaUsuarioAvatar" aria-hidden="true"></span>
          <span class="sofia-usuario-texto">
            <strong id="sofiaUsuarioNome"></strong>
            <small id="sofiaUsuarioPerfil"></small>
          </span>
        </div>
      </aside>

      <div class="sofia-ai-main">
        <header class="sofia-topo">
          <div class="sofia-topo-esq">
            <button
              type="button"
              class="icon-button sofia-abrir-historico"
              id="sofiaAbrirHistorico"
              aria-label="Abrir chats"
            >
              <i data-lucide="list"></i>
            </button>
            <h2 class="sofia-titulo-conversa" id="sofiaTituloConversa" hidden>Novo chat</h2>
          </div>

          <div class="sofia-modos" role="group" aria-label="Modo da Sofia">
            <button type="button" class="active" id="sofiaSearchMode">Pesquisar</button>
            <button type="button" id="sofiaComputerMode">Base HC</button>
          </div>

          <div class="sofia-topo-dir">
            <button type="button" class="sofia-modelo" id="sofiaModelBtn" title="Modelo da Sofia">
              Sofia
            </button>
          </div>
        </header>

        <div class="sofia-coluna" id="sofiaColuna">
          <div class="sofia-palco" id="sofiaPalco">
            <div class="chat" id="chat" aria-live="polite"></div>
          </div>

          <div class="sofia-centro">
            <div class="sofia-welcome" id="sofiaWelcome">
              <div class="sofia-marca" aria-hidden="true"><span>S</span></div>
              <p class="sofia-kicker">Assistente oficial do HC Brain</p>
              <h1 id="sofiaSaudacao">O que você quer saber?</h1>
              <p class="sofia-welcome-texto" id="sofiaWelcomeTexto">
                Pergunte por texto, grave um áudio ou anexe um arquivo. A Sofia consulta empresas,
                reuniões, documentos, projetos e processos da base.
              </p>
            </div>

            <div class="sofia-compose-wrap" id="sofiaCompose">
              <form class="chat-form" id="chatForm">
                <div class="sofia-attachment" id="sofiaAttachment">
                  <i data-lucide="paperclip"></i>
                  <span class="sofia-attachment-name" id="sofiaAttachmentName"></span>
                  <button
                    type="button"
                    class="icon-button"
                    id="removeSofiaAttachment"
                    aria-label="Remover arquivo"
                  >
                    <i data-lucide="x"></i>
                  </button>
                </div>

                {{--
                  A gravação ocupa o lugar da pílula: cancelar à esquerda, a onda e o
                  tempo no meio, parar e enviar à direita. Parar devolve o texto ao
                  campo para edição; só a seta envia.
                --}}
                <div class="sofia-gravacao" id="sofiaGravacao" hidden role="region" aria-label="Gravação de áudio">
                  <div class="sofia-gravacao-linha">
                    <button
                      type="button"
                      class="sofia-gravacao-botao"
                      id="sofiaGravacaoCancelar"
                      title="Cancelar gravação"
                      aria-label="Cancelar gravação"
                    >
                      <i data-lucide="x"></i>
                    </button>

                    <span class="sofia-gravacao-pulso" aria-hidden="true"></span>
                    <strong class="sofia-gravacao-estado" id="sofiaGravacaoEstado">Ouvindo…</strong>

                    <span class="sofia-onda" aria-hidden="true">
                      @for ($barra = 0; $barra < 9; $barra++)
                        <i></i>
                      @endfor
                    </span>

                    <span class="sofia-gravacao-tempo" id="sofiaGravacaoTempo">0:00</span>

                    <button
                      type="button"
                      class="sofia-gravacao-botao"
                      id="sofiaGravacaoParar"
                      title="Parar e editar o texto"
                      aria-label="Parar e editar o texto"
                    >
                      <i data-lucide="square"></i>
                    </button>

                    <button
                      type="button"
                      class="sofia-gravacao-enviar"
                      id="sofiaGravacaoEnviar"
                      title="Enviar pergunta"
                      aria-label="Enviar pergunta"
                    >
                      <i data-lucide="arrow-up"></i>
                    </button>
                  </div>

                  <p class="sofia-gravacao-parcial" id="sofiaGravacaoParcial" aria-live="polite">
                    Fale sua pergunta.
                  </p>
                </div>

                <div class="sofia-pill">
                  <input type="file" id="sofiaFileInput" hidden multiple accept="audio/*,.webm,.mp3,.m4a,.wav,.ogg,.mpeg,.mp4" />

                  <button type="button" class="sofia-pill-btn" id="sofiaAttachBtn" title="Anexar áudio">
                    <i data-lucide="plus"></i>
                    <span class="sr-only">Anexar áudio</span>
                  </button>

                  <label class="sr-only" for="question">Sua pergunta</label>
                  <textarea
                    id="question"
                    rows="1"
                    autocomplete="off"
                    placeholder="Pergunte à Sofia ou grave um áudio"
                  ></textarea>

                  {{--
                    O invólucro do microfone existe para o estado sem voz: um botão
                    desabilitado não recebe clique, então é ele quem explica o que fazer.
                  --}}
                  <span class="sofia-mic-wrap" id="sofiaMicWrap">
                    <button
                      type="button"
                      class="sofia-pill-btn sofia-mic"
                      id="sofiaMicBtn"
                      title="Gravar áudio"
                      aria-pressed="false"
                      aria-label="Gravar pergunta em áudio"
                    >
                      <i data-lucide="mic"></i>
                    </button>
                  </span>

                  {{-- Mesmo arranjo do microfone: o invólucro explica quando o botão não pode agir. --}}
                  <span class="sofia-voz-wrap" id="sofiaVozWrap">
                    <button
                      type="button"
                      class="sofia-pill-btn sofia-voz-btn"
                      id="sofiaVozBtn"
                      title="Iniciar conversa por voz"
                      aria-pressed="false"
                      aria-label="Iniciar conversa por voz"
                    >
                      <i data-lucide="audio-lines"></i>
                    </button>
                  </span>

                  <button class="sofia-send" id="sofiaSend" type="submit" aria-label="Enviar pergunta" disabled>
                    <i data-lucide="arrow-up"></i>
                  </button>
                </div>
              </form>

              <p class="sofia-dica" id="sofiaDicaVoz" hidden></p>

              <p class="sofia-aviso" id="sofiaAviso" role="alert" hidden></p>

              <small class="sofia-disclaimer">
                Assistente oficial do HC Brain. Confira informações importantes na base antes de decidir.
              </small>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  {{--
    A conversa por voz toma a tela inteira: só a esfera, o estado e a saída.
    O script move este bloco para o fim do `body`, porque enquanto ele está
    aberto o `main` inteiro fica inerte — e ele não pode ficar junto.
  --}}
  <div
    class="sofia-voz-tela"
    id="sofiaVozTela"
    hidden
    role="dialog"
    aria-modal="true"
    aria-labelledby="sofiaVozTitulo"
  >
    <h2 class="sr-only" id="sofiaVozTitulo">Conversa por voz com a Sofia</h2>

    <button
      type="button"
      class="sofia-voz-fechar"
      id="sofiaVozFechar"
      title="Sair da conversa por voz"
      aria-label="Sair da conversa por voz"
    >
      <i data-lucide="x"></i>
    </button>

    <div class="sofia-esfera" id="sofiaEsfera" data-estado="ouvindo" aria-hidden="true">
      <span class="sofia-esfera-brilho"></span>
      <span class="sofia-esfera-anel"></span>
      <span class="sofia-esfera-nucleo">
        <i></i>
        <i></i>
        <i></i>
      </span>
    </div>

    <p class="sofia-voz-estado" id="sofiaVozEstado" role="status" aria-live="polite">
      Ouvindo você…
    </p>

    <p class="sofia-voz-legenda" id="sofiaVozParcial">Fale sua pergunta.</p>

    <div class="sofia-voz-acoes">
      <button type="button" class="sofia-voz-interromper" id="sofiaVozInterromper" hidden>
        <i data-lucide="square"></i>
        <span>Interromper</span>
      </button>

      <button type="button" class="sofia-voz-sair" id="sofiaVozSair">Sair da conversa</button>
    </div>
  </div>
@endsection
