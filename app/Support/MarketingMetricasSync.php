<?php

namespace App\Support;

use App\Models\MarketingConteudo;
use App\Models\MarketingMetrica;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sincroniza métricas de redes para o painel Marketing.
 *
 * YouTube: Data API v3 (API key) — views/likes por vídeo e inscritos totais.
 * Instagram: ainda aguarda token Meta (HC_INSTAGRAM_*).
 */
final class MarketingMetricasSync
{
    private const YOUTUBE_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * @return array{periodos: int, aviso: string|null}
     */
    public static function sincronizar(): array
    {
        $periodos = 0;
        $avisos = [];

        $youtube = self::sincronizarYoutube();
        $periodos += $youtube['periodos'];
        if ($youtube['aviso'] !== null) {
            $avisos[] = $youtube['aviso'];
        }

        if (! self::instagramConfigurado()) {
            $avisos[] = 'Instagram ainda sem token Meta; mantendo dados locais.';
        }

        return [
            'periodos' => $periodos,
            'aviso' => $avisos === [] ? null : implode(' ', $avisos),
        ];
    }

    /**
     * @return array{periodos: int, aviso: string|null}
     */
    private static function sincronizarYoutube(): array
    {
        $chave = (string) config('hc.marketing.youtube_api_key');
        $canal = (string) config('hc.marketing.youtube_channel_id');

        if ($chave === '' || $canal === '') {
            return [
                'periodos' => 0,
                'aviso' => 'YouTube sem HC_YOUTUBE_API_KEY / HC_YOUTUBE_CHANNEL_ID.',
            ];
        }

        try {
            $canalDados = self::youtubeGet('channels', [
                'part' => 'statistics,contentDetails',
                'id' => $canal,
            ], $chave);

            $item = $canalDados['items'][0] ?? null;
            if (! is_array($item)) {
                return ['periodos' => 0, 'aviso' => 'YouTube: canal não encontrado.'];
            }

            $stats = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
            $inscritosTotal = (int) ($stats['subscriberCount'] ?? 0);
            $uploads = (string) data_get($item, 'contentDetails.relatedPlaylists.uploads', '');

            if ($uploads === '') {
                return ['periodos' => 0, 'aviso' => 'YouTube: playlist de uploads ausente.'];
            }

            $videos = self::videosDoCanal($uploads, $chave);
            $porMes = self::agruparVideosPorMes($videos);
            $periodoAtual = now()->format('Y-m');
            $periodos = 0;

            foreach ($porMes as $periodo => $lista) {
                $views = 0;
                $likes = 0;
                $comentarios = 0;

                foreach ($lista as $video) {
                    $views += $video['views'];
                    $likes += $video['likes'];
                    $comentarios += $video['comentarios'];
                }

                $anterior = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->subMonth()->format('Y-m');
                $metricaAnterior = MarketingMetrica::query()
                    ->where('periodo', $anterior)
                    ->where('plataforma', 'youtube')
                    ->first();

                $seguidores = $periodo === $periodoAtual
                    ? $inscritosTotal
                    : (int) ($metricaAnterior?->seguidores ?? 0);

                // Sem Analytics: saldo líquido vs snapshot anterior (pode ser 0 na 1ª sync).
                $ganho = 0;
                if ($periodo === $periodoAtual && $metricaAnterior !== null && $metricaAnterior->seguidores > 0) {
                    $ganho = max(0, $inscritosTotal - (int) $metricaAnterior->seguidores);
                } elseif ($periodo === $periodoAtual) {
                    $existente = MarketingMetrica::query()
                        ->where('periodo', $periodo)
                        ->where('plataforma', 'youtube')
                        ->first();
                    $ganho = (int) ($existente?->inscritos_ou_alcance ?? 0);
                }

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodo, 'plataforma' => 'youtube'],
                    [
                        'views' => $views,
                        'likes' => $likes,
                        'comentarios' => $comentarios,
                        'compartilhamentos' => 0,
                        'inscritos_ou_alcance' => $ganho,
                        'seguidores' => $periodo === $periodoAtual ? $inscritosTotal : $seguidores,
                    ],
                );

                $ranking = collect($lista)->sortByDesc('views')->values();
                MarketingConteudo::query()
                    ->where('plataforma', 'youtube')
                    ->where('periodo', $periodo)
                    ->update(['destaque' => false]);

                foreach ($ranking->take(5) as $indice => $video) {
                    if (! is_array($video)) {
                        continue;
                    }

                    MarketingConteudo::query()->updateOrCreate(
                        [
                            'titulo' => $video['titulo'],
                            'plataforma' => 'youtube',
                            'periodo' => $periodo,
                        ],
                        [
                            'publicado_em' => $video['publicado_em'],
                            'views' => $video['views'],
                            'likes' => $video['likes'],
                            'comentarios' => $video['comentarios'],
                            'ganho_inscritos' => 0,
                            'ganho_seguidores' => 0,
                            'destaque' => $indice === 0,
                            'url' => $video['url'],
                        ],
                    );
                }

                $periodos++;
            }

            // Garante o mês atual mesmo sem uploads novos (só atualiza inscritos totais).
            if (! isset($porMes[$periodoAtual])) {
                $anterior = Carbon::createFromFormat('Y-m', $periodoAtual)->startOfMonth()->subMonth()->format('Y-m');
                $metricaAnterior = MarketingMetrica::query()
                    ->where('periodo', $anterior)
                    ->where('plataforma', 'youtube')
                    ->first();
                $existente = MarketingMetrica::query()
                    ->where('periodo', $periodoAtual)
                    ->where('plataforma', 'youtube')
                    ->first();
                $ganho = $metricaAnterior !== null && $metricaAnterior->seguidores > 0
                    ? max(0, $inscritosTotal - (int) $metricaAnterior->seguidores)
                    : (int) ($existente?->inscritos_ou_alcance ?? 0);

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodoAtual, 'plataforma' => 'youtube'],
                    [
                        'views' => (int) ($existente?->views ?? 0),
                        'likes' => (int) ($existente?->likes ?? 0),
                        'comentarios' => (int) ($existente?->comentarios ?? 0),
                        'compartilhamentos' => (int) ($existente?->compartilhamentos ?? 0),
                        'inscritos_ou_alcance' => $ganho,
                        'seguidores' => $inscritosTotal,
                    ],
                );
                $periodos++;
            }

            return ['periodos' => $periodos, 'aviso' => null];
        } catch (Throwable $erro) {
            return [
                'periodos' => 0,
                'aviso' => 'YouTube: '.$erro->getMessage(),
            ];
        }
    }

    /**
     * @return list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>
     */
    private static function videosDoCanal(string $uploadsPlaylistId, string $chave): array
    {
        $videoIds = [];
        $pageToken = null;

        // Até 100 vídeos recentes — suficiente para o painel mensal.
        for ($pagina = 0; $pagina < 2; $pagina++) {
            $params = [
                'part' => 'contentDetails,snippet',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => 50,
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $lista = self::youtubeGet('playlistItems', $params, $chave);
            foreach ($lista['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) data_get($item, 'contentDetails.videoId', '');
                if ($id !== '') {
                    $videoIds[] = $id;
                }
            }

            $pageToken = $lista['nextPageToken'] ?? null;
            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        if ($videoIds === []) {
            return [];
        }

        $videos = [];
        foreach (array_chunk($videoIds, 50) as $lote) {
            $detalhe = self::youtubeGet('videos', [
                'part' => 'snippet,statistics',
                'id' => implode(',', $lote),
            ], $chave);

            foreach ($detalhe['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
                $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
                $publicado = (string) ($snippet['publishedAt'] ?? '');
                if ($id === '' || $publicado === '') {
                    continue;
                }

                $videos[] = [
                    'titulo' => mb_substr((string) ($snippet['title'] ?? 'Sem título'), 0, 255),
                    'publicado_em' => Carbon::parse($publicado)->toDateString(),
                    'views' => (int) ($statistics['viewCount'] ?? 0),
                    'likes' => (int) ($statistics['likeCount'] ?? 0),
                    'comentarios' => (int) ($statistics['commentCount'] ?? 0),
                    'url' => 'https://www.youtube.com/watch?v='.$id,
                ];
            }
        }

        return $videos;
    }

    /**
     * @param  list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>  $videos
     * @return array<string, list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>>
     */
    private static function agruparVideosPorMes(array $videos): array
    {
        $porMes = [];

        foreach ($videos as $video) {
            $periodo = Carbon::parse($video['publicado_em'])->format('Y-m');
            $porMes[$periodo][] = $video;
        }

        ksort($porMes);

        return $porMes;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private static function youtubeGet(string $recurso, array $params, string $chave): array
    {
        try {
            /** @var array<string, mixed> $json */
            $json = Http::connectTimeout(5)
                ->timeout(20)
                ->acceptJson()
                ->get(self::YOUTUBE_BASE.'/'.$recurso, [...$params, 'key' => $chave])
                ->throw()
                ->json();

            return $json;
        } catch (RequestException $excecao) {
            $corpo = $excecao->response?->json();
            $mensagem = is_array($corpo)
                ? (string) data_get($corpo, 'error.message', $excecao->getMessage())
                : $excecao->getMessage();

            throw new \RuntimeException($mensagem, previous: $excecao);
        }
    }

    private static function instagramConfigurado(): bool
    {
        return (string) config('hc.marketing.instagram_access_token') !== ''
            && (string) config('hc.marketing.instagram_user_id') !== '';
    }
}
