<?php

namespace App\Http\Controllers;

use App\Models\ContaGoogle;
use App\Support\GoogleAgenda;
use App\Support\Telas;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A ida e a volta do consentimento do Google. Quem conecta é um
 * administrador; a agenda conectada é a da conta Google escolhida na tela do
 * Google, não a de quem está logado no HC Brain.
 */
class GoogleController extends Controller
{
    private const ESTADO = 'google_estado';

    public function conectar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador conecta agendas do Google.');
        abort_unless(GoogleAgenda::configurada(), 503, 'Defina GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET no ambiente para conectar agendas.');

        $estado = Str::random(40);
        $request->session()->put(self::ESTADO, $estado);

        return redirect()->away(GoogleAgenda::urlDeAutorizacao($estado));
    }

    /**
     * O `state` amarra o retorno ao pedido feito nesta sessão: sem ele, um
     * link forjado conectaria a agenda de outra pessoa ao HC Brain.
     */
    public function retorno(Request $request): RedirectResponse
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador conecta agendas do Google.');

        $estado = $request->session()->pull(self::ESTADO);

        if (! is_string($estado) || ! hash_equals($estado, (string) $request->query('state'))) {
            // Sessão perdeu o state (aba fechou, cookie, ou demorou demais no Google).
            return $this->voltar('sessao');
        }

        // Quem clica em "Cancelar" na tela do Google volta sem código.
        if (! $request->filled('code')) {
            return $this->voltar('recusada');
        }

        try {
            $dados = GoogleAgenda::trocarCodigo($request->string('code')->toString());
        } catch (HttpClientException $erro) {
            report($erro);

            // Quase sempre: redirect URI diferente do Cloud, ou Client ID/Secret trocados.
            return $this->voltar('oauth');
        }

        $conta = ContaGoogle::firstOrNew(['email' => $dados['email']]);

        if ($dados['refresh_token'] === null && ! $conta->exists) {
            // Conta nova sem refresh_token: o Google não deu acesso offline.
            return $this->voltar('sem_refresh');
        }

        $conta->fill([
            'nome' => $dados['nome'],
            'erro' => null,
            ...($dados['refresh_token'] !== null ? ['refresh_token' => $dados['refresh_token']] : []),
        ])->save();

        return $this->voltar('conectada');
    }

    private function voltar(string $resultado): RedirectResponse
    {
        return redirect()->to(Telas::caminho('usuarios').'?google='.$resultado);
    }
}
