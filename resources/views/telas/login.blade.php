@extends('layouts.auth', [
    'tela' => 'login',
    'titulo' => 'Entrar',
    'descricao' => 'Acesse a memória central da Health Care.',
])

@section('conteudo')
  <div class="login-screen" id="loginScreen">
    <div class="login-card">
      <div class="login-brand">
        <div class="login-logo">HC</div>
        <div>
          <strong>HC Brain</strong>
          <span>Ambiente de conhecimento da Health Care</span>
        </div>
      </div>

      <h1 class="login-title">Entrar no HC Brain</h1>
      <p class="login-subtitle">
        Acesse a memória central da HC, empresas, reuniões, documentos, treinamentos,
        financeiro e a Sofia.
      </p>

      <form id="loginForm" method="POST" action="{{ route('login') }}">
        @csrf

        <div class="login-field">
          <label for="loginEmail">E-mail</label>
          <input
            id="loginEmail"
            name="email"
            type="email"
            autocomplete="username"
            placeholder="Digite seu e-mail"
            value="{{ old('email', $emailLembrado) }}"
            required
          />
        </div>

        <div class="login-field">
          <label for="loginPassword">Senha</label>
          <input
            id="loginPassword"
            name="password"
            type="password"
            autocomplete="current-password"
            placeholder="Digite sua senha"
            required
          />
        </div>

        <div class="login-options">
          <label class="login-check">
            <input id="rememberLogin" name="remember" type="checkbox" value="1"
                   @checked(old('remember', $emailLembrado !== null)) />
            <span>Lembrar meu acesso</span>
          </label>
          <button type="button" class="login-link" id="forgotLogin">
            Esqueci minha senha
          </button>
        </div>

        <button class="login-submit" type="submit">Entrar no HC Brain</button>

        <div class="login-error @error('email') show @enderror" id="loginError">
          @error('email'){{ $message }}@else E-mail ou senha incorretos. Verifique os dados e tente novamente. @enderror
        </div>
      </form>
    </div>
  </div>
@endsection
