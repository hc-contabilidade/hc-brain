@extends('layouts.app', [
    'tela' => 'usuarios',
    'titulo' => 'Usuários',
    'descricao' => 'Usuários com acesso ao HC Brain, perfis, áreas e últimos acessos.',
])

@section('conteudo')
  {{-- Gerenciamento dos usuários e acessos da equipe. --}}
  <section class="page active" id="usuarios">
    <div class="page-header">
      <div class="page-header-copy">
        <span class="page-overline">Acesso • equipe da HC</span>
        <h1>Usuários</h1>
        <p class="muted">
          Gerencie os usuários que possuem acesso ao HC Brain e acompanhe seus perfis
          e últimos acessos.
        </p>
      </div>

      <div class="page-header-actions">
        <button class="primary" id="novoUsuarioBtn" type="button">Cadastrar usuário</button>
      </div>
    </div>

    <div class="usuarios-summary">
      <div class="usuario-kpi">
        <span>Total de usuários</span><strong id="usuariosTotal">0</strong>
      </div>
      <div class="usuario-kpi">
        <span>Usuários ativos</span><strong id="usuariosAtivos">0</strong>
      </div>
      <div class="usuario-kpi">
        <span>Administradores</span><strong id="usuariosAdmins">0</strong>
      </div>
      <div class="usuario-kpi">
        <span>Último acesso</span><strong id="usuariosUltimo" class="usuario-kpi-texto">—</strong>
      </div>
    </div>

    <div class="filtros">
      <div class="campo">
        <label for="usuarioSearch">Pesquisar usuário</label>
        <input id="usuarioSearch" placeholder="Nome, e-mail ou área..." autocomplete="off" />
      </div>
      <div class="campo">
        <label for="usuarioRoleFilter">Perfil</label>
        <select id="usuarioRoleFilter">
          <option value="Todos">Todos os perfis</option>
          <option value="Administrador">Administrador</option>
          <option value="Gestor">Gestor</option>
          <option value="Colaborador">Colaborador</option>
        </select>
      </div>
      <div class="campo">
        <label for="usuarioStatusFilter">Status</label>
        <select id="usuarioStatusFilter">
          <option value="Todos">Todos</option>
          <option value="Ativo">Ativos</option>
          <option value="Inativo">Inativos</option>
        </select>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Usuários cadastrados</h2>
        <span class="panel-count" id="usuariosCount">0 usuários</span>
      </div>
      <div class="tabela-wrap">
        <table class="tabela usuarios-table">
          <thead>
            <tr>
              <th>Usuário</th>
              <th>Perfil</th>
              <th>Área</th>
              <th>Status</th>
              <th>Último acesso</th>
              <th><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody id="usuariosTableBody">
            <tr>
              <td colspan="6">
                <div class="estado-carregando">Carregando usuários…</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="usuarios-paginacao" id="usuariosPaginacao" hidden>
        <div class="usuarios-paginacao-meta">
          <label class="sr-only" for="usuarioPorPagina">Resultados por página</label>
          <select id="usuarioPorPagina" class="usuarios-por-pagina">
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="50">50</option>
          </select>
          <span id="usuariosPaginacaoInfo"></span>
        </div>
        <nav class="usuarios-paginacao-nav" id="usuariosPaginacaoNav" aria-label="Páginas de usuários"></nav>
      </div>
    </div>

    {{-- Só administradores veem: o script mostra o painel conforme o perfil. --}}
    <div class="panel agendas-google" id="agendasGoogle" hidden>
      <div class="panel-head">
        <div>
          <h2>Agendas do Google</h2>
          <p class="muted">
            Os eventos com Meet destas agendas entram em Reuniões a cada 5 minutos.
          </p>
        </div>
        <div class="page-header-actions">
          <button type="button" class="secondary" id="sincronizarAgendas" hidden>Sincronizar agora</button>
          <a class="primary" id="conectarAgenda" href="{{ route('google.conectar') }}">Conectar conta Google</a>
        </div>
      </div>
      <div id="agendasGoogleLista"></div>
    </div>
  </section>
@endsection
