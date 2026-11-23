<?php

namespace App\Support;

use App\Models\ContaYoutubeAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth + YouTube Analytics API v2 (Insights do canal, como no Studio).
 */
final class YouTubeAnalytics
{
    private const AUTORIZACAO = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const PERFIL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const ANALYTICS = 'https://youtubeanalytics.googleapis.com/v2/reports';

    private const DATA_API = 'https://www.googleapis.com/youtube/v3';

    private const ESCOPOS = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    public static function configurada(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public static function conectada(): bool
    {
        return self::contaAtiva() !== null;
    }

    public static function contaAtiva(): ?ContaYoutubeAnalytics
    {
        return ContaYoutubeAnalytics::query()->orderByDesc('id')->first();
    }

    public static function enderecoDeRetorno(): string
    {
        // Mesma URI das agendas (já autorizada no Cloud) — evita redirect_uri_mismatch.
        return (string) (config('services.google.redirect') ?: route('google.retorno'));
    }

    public static function urlDeAutorizacao(string $estado): string
    {
        return self::AUTORIZACAO.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => self::enderecoDeRetorno(),
            'response_type' => 'code',
            'scope' => implode(' ', self::ESCOPOS),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $estado,
        ]);
    }

    /**
     * @return array{email: string, nome: string, refresh_token: string|null, channel_id: string|null}
     */
    public static function trocarCodigo(string $codigo): array
    {
        $tokens = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $codigo,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => self::enderecoDeRetorno(),
            'grant_type' => 'authorization_code',
        ])->throw();

        $accessToken = (string) $tokens->json('access_token');

        $perfil = Http::withToken($accessToken)
            ->timeout(15)
            ->get(self::PERFIL)
            ->throw();

        $email = (string) $perfil->json('email');
        $channelId = self::descobrirChannelId($accessToken);

        return [
            'email' => $email,
            'nome' => (string) ($perfil->json('name') ?: $email),
            'refresh_token' => $tokens->json('refresh_token'),
            'channel_id' => $channelId,
        ];
    }

    public static function tokenDeAcesso(ContaYoutubeAnalytics $conta): string
    {
        $resposta = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'refresh_token' => $conta->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if ($resposta->json('error') === 'invalid_grant') {
            $conta->update(['erro' => 'O Google recusou o acesso: conecte o YouTube Analytics de novo.']);
            throw new RuntimeException('O Google recusou o acesso desta conta YouTube: conecte-a de novo.');
        }

        return (string) $resposta->throw()->json('access_token');
    }

    public static function descobrirChannelId(string $accessToken): ?string
    {
        $resposta = Http::withToken($accessToken)
            ->timeout(15)
            ->get(self::DATA_API.'/channels', [
                'part' => 'id,snippet',
                'mine' => 'true',
            ]);

        if (! $resposta->successful()) {
            return null;
        }

        $id = data_get($resposta->json(), 'items.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Relatório mensal de Insights do canal.
     * Com dimensions=month a API exige início no 1º dia e fim no último dia de algum mês.
     *
     * @return list<array{periodo: string, views: int, likes: int, comentarios: int, compartilhamentos: int, inscritos_ganhos: int, inscritos_perdidos: int}>
     */
    public static function metricasMensais(
        string $accessToken,
        string $channelId,
        string $inicio,
        string $fim,
    ): array {
        $resposta = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::ANALYTICS, [
                'ids' => 'channel=='.$channelId,
                'startDate' => $inicio,
                'endDate' => $fim,
                'metrics' => 'views,likes,comments,shares,subscribersGained,subscribersLost',
                'dimensions' => 'month',
                'sort' => 'month',
            ])
            ->throw()
            ->json();

        $linhas = [];

        foreach ($resposta['rows'] ?? [] as $row) {
            if (! is_array($row) || count($row) < 7) {
                continue;
            }

            $mes = (string) $row[0]; // YYYY-MM
            $linhas[] = [
                'periodo' => $mes,
                'views' => (int) $row[1],
                'likes' => (int) $row[2],
                'comentarios' => (int) $row[3],
                'compartilhamentos' => (int) $row[4],
                'inscritos_ganhos' => (int) $row[5],
                'inscritos_perdidos' => (int) $row[6],
            ];
        }

        return $linhas;
    }

    /**
     * Totais do canal num intervalo (mês parcial). Sem dimensions=month.
     *
     * @return array{periodo: string, views: int, likes: int, comentarios: int, compartilhamentos: int, inscritos_ganhos: int, inscritos_perdidos: int}|null
     */
    public static function metricasPeriodo(
        string $accessToken,
        string $channelId,
        string $inicio,
        string $fim,
    ): ?array {
        $resposta = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::ANALYTICS, [
                'ids' => 'channel=='.$channelId,
                'startDate' => $inicio,
                'endDate' => $fim,
                'metrics' => 'views,likes,comments,shares,subscribersGained,subscribersLost',
            ])
            ->throw()
            ->json();

        $row = $resposta['rows'][0] ?? null;
        if (! is_array($row) || count($row) < 6) {
            return null;
        }

        return [
            'periodo' => Carbon::parse($inicio)->format('Y-m'),
            'views' => (int) $row[0],
            'likes' => (int) $row[1],
            'comentarios' => (int) $row[2],
            'compartilhamentos' => (int) $row[3],
            'inscritos_ganhos' => (int) $row[4],
            'inscritos_perdidos' => (int) $row[5],
        ];
    }

    /**
     * Top vídeos por views no período (Insights).
     *
     * @return list<array{video_id: string, views: int, likes: int, comentarios: int, compartilhamentos: int}>
     */
    public static function rankingVideos(
        string $accessToken,
        string $channelId,
        string $inicio,
        string $fim,
        int $limite = 10,
    ): array {
        $resposta = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::ANALYTICS, [
                'ids' => 'channel=='.$channelId,
                'startDate' => $inicio,
                'endDate' => $fim,
                'metrics' => 'views,likes,comments,shares',
                'dimensions' => 'video',
                'sort' => '-views',
                'maxResults' => $limite,
            ])
            ->throw()
            ->json();

        $itens = [];

        foreach ($resposta['rows'] ?? [] as $row) {
            if (! is_array($row) || count($row) < 5) {
                continue;
            }

            $itens[] = [
                'video_id' => (string) $row[0],
                'views' => (int) $row[1],
                'likes' => (int) $row[2],
                'comentarios' => (int) $row[3],
                'compartilhamentos' => (int) $row[4],
            ];
        }

        return $itens;
    }

    /**
     * @param  list<string>  $videoIds
     * @return array<string, array{titulo: string, publicado_em: string, formato: string, url: string}>
     */
    public static function metadadosVideos(string $accessToken, array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }

        $mapa = [];

        foreach (array_chunk($videoIds, 50) as $lote) {
            $resposta = Http::withToken($accessToken)
                ->timeout(20)
                ->get(self::DATA_API.'/videos', [
                    'part' => 'snippet,contentDetails',
                    'id' => implode(',', $lote),
                ])
                ->throw()
                ->json();

            foreach ($resposta['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
                $contentDetails = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
                $titulo = mb_substr((string) ($snippet['title'] ?? 'Sem título'), 0, 255);
                $descricao = (string) ($snippet['description'] ?? '');
                $publicado = (string) ($snippet['publishedAt'] ?? '');
                $duracao = self::duracaoEmSegundos((string) ($contentDetails['duration'] ?? ''));
                $ehShort = self::eYoutubeShort($titulo, $descricao, $duracao);

                if ($id === '' || $publicado === '') {
                    continue;
                }

                $mapa[$id] = [
                    'titulo' => $titulo,
                    'publicado_em' => Carbon::parse($publicado)->toDateString(),
                    'formato' => $ehShort ? 'short' : 'video',
                    'url' => $ehShort
                        ? 'https://www.youtube.com/shorts/'.$id
                        : 'https://www.youtube.com/watch?v='.$id,
                ];
            }
        }

        return $mapa;
    }

    public static function inscritosTotais(string $accessToken, string $channelId): int
    {
        $resposta = Http::withToken($accessToken)
            ->timeout(15)
            ->get(self::DATA_API.'/channels', [
                'part' => 'statistics',
                'id' => $channelId,
            ])
            ->throw()
            ->json();

        return (int) data_get($resposta, 'items.0.statistics.subscriberCount', 0);
    }

    private static function duracaoEmSegundos(string $iso8601): int
    {
        if ($iso8601 === '' || ! str_starts_with($iso8601, 'PT')) {
            return 0;
        }

        $segundos = 0;
        if (preg_match('/(\d+)H/', $iso8601, $horas) === 1) {
            $segundos += (int) $horas[1] * 3600;
        }
        if (preg_match('/(\d+)M/', $iso8601, $minutos) === 1) {
            $segundos += (int) $minutos[1] * 60;
        }
        if (preg_match('/(\d+)S/', $iso8601, $segs) === 1) {
            $segundos += (int) $segs[1];
        }

        return $segundos;
    }

    private static function eYoutubeShort(string $titulo, string $descricao, int $duracaoSegundos): bool
    {
        $texto = mb_strtolower($titulo.' '.$descricao);
        if (str_contains($texto, '#shorts') || str_contains($texto, '#short')) {
            return true;
        }

        return $duracaoSegundos > 0 && $duracaoSegundos <= 180;
    }
}
