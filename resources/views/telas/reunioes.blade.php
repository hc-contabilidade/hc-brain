@extends('layouts.app', [
    'tela' => 'reunioes',
    'titulo' => 'Reuniões',
    'descricao' => 'Histórico central de reuniões da HC por empresa, tipo e sócio responsável.',
])

@section('conteudo')
  {{-- Agenda de reuniões: resumo do recorte, movimento por dia e o histórico
       em lista, cartões ou calendário. --}}
  <section class="page active" id="reunioes">
    <div class="page-header">
      <div class="page-header-copy">
        <span class="page-overline">Histórico central de reuniões</span>
        <h1>Agenda de reuniões</h1>
        <p class="muted">
          Encontre cada reunião por data, tipo ou responsável e abra os detalhes
          quando precisar.
        </p>
      </div>
    </div>

    <section class="agenda-resumo" aria-label="Resumo do período">
      <div class="agenda-resumo-topo">
        <h2>Resumo do período</h2>
        <span class="agenda-intervalo" id="agendaIntervalo">—</span>
      </div>
      <div class="agenda-stats" id="agendaStats"></div>
    </section>

    <section class="agenda-movimento" aria-label="Movimento das reuniões por dia">
      <div class="agenda-movimento-copy">
        <span class="agenda-overline">Visão rápida</span>
        <h2>Movimento no período</h2>
        <p class="muted">Selecione uma coluna para ver as reuniões daquele dia.</p>
        <ul class="agenda-legenda">
          <li class="verde">Concluídas</li>
          <li class="azul">Agendadas</li>
          <li class="vermelho">Canceladas</li>
        </ul>
      </div>
      <div class="agenda-grafico" id="agendaGrafico"></div>
    </section>

    <section class="agenda-painel" aria-label="Reuniões">
      <div class="agenda-painel-topo">
        <div>
          <h2>Reuniões do período</h2>
          <p class="muted">
            Aberturas, transferências, dúvidas, comerciais, alinhamentos e
            financeiras na mesma ordem cronológica.
          </p>
        </div>

        <div class="agenda-segmentado" id="agendaPeriodo" role="group" aria-label="Período rápido">
          <button type="button" data-periodo="hoje">Hoje</button>
          <button type="button" data-periodo="7dias">7 dias</button>
          <button type="button" data-periodo="mes">Este mês</button>
          <button type="button" data-periodo="tudo">Todo período</button>
        </div>
      </div>

      <div class="agenda-filtros">
        <div class="campo agenda-campo-busca">
          <label for="meetingSearch">Buscar reunião</label>
          <div class="agenda-busca">
            <span class="search-icon"><i data-lucide="search"></i></span>
            <input
              id="meetingSearch"
              type="search"
              autocomplete="off"
              placeholder="Empresa, tipo, resumo ou responsável..."
            />
          </div>
        </div>

        <div class="campo">
          <label for="meetingTypeFilter">Tipo</label>
          <select id="meetingTypeFilter">
            <option value="Todas">Todos</option>
            <option value="Abertura">Abertura</option>
            <option value="Transferência">Transferência</option>
            <option value="Dúvidas">Dúvidas</option>
            <option value="Comercial">Comercial</option>
            <option value="Alinhamento">Alinhamento</option>
            <option value="Financeira">Financeira</option>
            <option value="Interna">Interna</option>
            <option value="Outra">Outra</option>
          </select>
        </div>

        <div class="campo">
          <label for="meetingStatusFilter">Status</label>
          <select id="meetingStatusFilter">
            <option value="Todos">Todos</option>
            <option value="Agendada">Agendada</option>
            <option value="Concluída">Concluída</option>
            <option value="Cancelada">Cancelada</option>
          </select>
        </div>

        <div class="campo">
          <label for="meetingResponsavelFilter">Quem realiza</label>
          <select id="meetingResponsavelFilter">
            <option value="Todos">Todos</option>
          </select>
        </div>

        <div class="campo">
          <label for="meetingEmpresaFilter">Empresa</label>
          <select id="meetingEmpresaFilter">
            <option value="Todas">Todas</option>
          </select>
        </div>

        <div class="campo">
          <label for="meetingFrom">De</label>
          <input id="meetingFrom" type="date" />
        </div>

        <div class="campo">
          <label for="meetingTo">Até</label>
          <input id="meetingTo" type="date" />
        </div>
      </div>

      <div class="agenda-rodape-filtros">
        <p class="agenda-contagem">
          <strong id="meetingCount">0</strong> reuniões encontradas
          <button id="clearMeetingFilters" class="agenda-reset" type="button">
            Limpar filtros
          </button>
        </p>

        {{--
          O calendário é um botão só, mas com cinco janelas possíveis. Elas
          saem num menu em vez de virarem cinco segmentos, que não caberiam
          ao lado de Lista e Cards.
        --}}
        <div class="agenda-visoes-grupo">
          <div class="view-toggle agenda-visoes" id="meetingViewToggle">
            <button type="button" class="active" data-meeting-view="list">
              <i data-lucide="list"></i> Lista
            </button>
            <button type="button" data-meeting-view="cards">
              <i data-lucide="layout-grid"></i> Cards
            </button>
            <button
              type="button"
              id="meetingScaleButton"
              data-meeting-view="calendar"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="meetingScaleMenu"
            >
              <i data-lucide="calendar-days"></i>
              <span id="meetingScaleLabel">Calendário</span>
              <i data-lucide="chevron-down" class="agenda-escala-seta"></i>
            </button>
          </div>

          <div class="agenda-escala-menu" id="meetingScaleMenu" role="menu" hidden>
            <button type="button" role="menuitemradio" data-escala="dia">Dia</button>
            <button type="button" role="menuitemradio" data-escala="4dias">4 dias</button>
            <button type="button" role="menuitemradio" data-escala="semana">Semana</button>
            <button type="button" role="menuitemradio" data-escala="mes">Mês</button>
            <button type="button" role="menuitemradio" data-escala="ano">Ano</button>
          </div>
        </div>
      </div>

      <div class="agenda-tabela" id="meetingsList">
        <div class="estado-carregando">Carregando reuniões…</div>
      </div>

      <div class="agenda-cards hidden-view" id="meetingsGrid"></div>

      <div class="agenda-calendario hidden-view" id="meetingsCalendar">
        <div class="meetings-calendar-head">
          <button type="button" class="icon-button" id="calendarPrev" aria-label="Período anterior"><i data-lucide="chevron-left"></i></button>
          <div class="calendar-month-title">
            <strong id="calendarMonthTitle">—</strong>
            <button type="button" class="secondary calendar-today" id="calendarToday">Hoje</button>
          </div>
          <button type="button" class="icon-button" id="calendarNext" aria-label="Próximo período"><i data-lucide="chevron-right"></i></button>
        </div>
        {{-- A régua de dias da semana só faz sentido sobre a grade do mês. --}}
        <div class="calendar-weekdays" id="calendarWeekdays" aria-hidden="true">
          <span>Dom</span><span>Seg</span><span>Ter</span><span>Qua</span>
          <span>Qui</span><span>Sex</span><span>Sáb</span>
        </div>
        <div class="calendar-days" id="calendarDays"></div>
      </div>
    </section>
  </section>
@endsection
