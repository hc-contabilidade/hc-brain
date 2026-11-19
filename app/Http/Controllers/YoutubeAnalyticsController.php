<?php

namespace App\Http\Controllers;

use App\Models\ContaYoutubeAnalytics;
use App\Support\Telas;
use App\Support\YouTubeAnalytics;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conecta a conta Google dona do canal HC ao YouTube Analytics (Insights).
 */
class YoutubeAnalyticsController extends Controller
{
    private const ESTADO = 'youtube_analytics_estado';

    public function conectar(Request $request): RedirectResponse
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador conecta o YouTube Analytics.');
        abort_unless(YouTubeAnalytics::configurada(), 503, 'Defina GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET no ambiente.');

        $estado = Str::random(40);
        $request->session()->put(self::ESTADO, $estado);

        return redirect()->away(YouTubeAnalytics::urlDeAutorizacao($estado));
    }

    public function retorno(Request $request): RedirectResponse
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador conecta o YouTube Analytics.');

        $estado = $request->session()->pull(self::ESTADO);

        if (! is_string($estado) || ! hash_equals($estado, (string) $request->query('state'))) {
            return $this->voltar('sessao');
        }

        if (! $request->filled('code')) {
            return $this->voltar('recusada');
        }

        try {
            $dados = YouTubeAnalytics::trocarCodigo($request->string('code')->toString());
        } catch (HttpClientException $erro) {
            report($erro);

            return $this->voltar('oauth');
        }

        $conta = ContaYoutubeAnalytics::query()->firstOrNew(['email' => $dados['email']]);

        if ($dados['refresh_token'] === null && ! $conta->exists) {
            return $this->voltar('sem_refresh');
        }

        if (($dados['channel_id'] ?? null) === null && $conta->channel_id === null) {
            $configChannel = (string) config('hc.marketing.youtube_channel_id');
            $dados['channel_id'] = $configChannel !== '' ? $configChannel : null;
        }

        if (($dados['channel_id'] ?? null) === null && blank($conta->channel_id)) {
            return $this->voltar('sem_canal');
        }

        $conta->fill([
            'nome' => $dados['nome'],
            'erro' => null,
            'channel_id' => $dados['channel_id'] ?? $conta->channel_id,
            ...($dados['refresh_token'] !== null ? ['refresh_token' => $dados['refresh_token']] : []),
        ])->save();

        // Só uma conta ativa de Insights.
        ContaYoutubeAnalytics::query()
            ->where('id', '!=', $conta->id)
            ->delete();

        return $this->voltar('conectada');
    }

    private function voltar(string $resultado): RedirectResponse
    {
        return redirect()->to(Telas::caminho('marketing').'?youtube='.$resultado);
    }
}
