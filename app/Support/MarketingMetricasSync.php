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
 * YouTube: Data API v3 (API key).
 * Instagram: Graph API (token Meta + IG user id).
 */
final class MarketingMetricasSync
{
    private const YOUTUBE_BASE = 'https://www.googleapis.com/youtube/v3';

    private const INSTAGRAM_BASE = 'https://graph.facebook.com/v21.0';

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

        $instagram = self::sincronizarInstagram();
        $periodos += $instagram['periodos'];
        if ($instagram['aviso'] !== null) {
            $avisos[] = $instagram['aviso'];
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
            $porMes = self::agruparPorMes($videos);
            $periodoAtual = now()->format('Y-m');
            $periodos = 0;

            $periodoAnterior = Carbon::createFromFormat('Y-m', $periodoAtual)
                ->startOfMonth()
                ->subMonth()
                ->format('Y-m');
            $seguidoresAnteriores = (int) (MarketingMetrica::query()
                ->where('plataforma', 'youtube')
                ->where('periodo', $periodoAnterior)
                ->value('seguidores') ?? 0);

            if (
                $seguidoresAnteriores > 0
                && $inscritosTotal > 0
                && $seguidoresAnteriores > max(500, $inscritosTotal * 2)
            ) {
                $seguidoresAnteriores = 0;
            }

            MarketingMetrica::query()
                ->where('plataforma', 'youtube')
                ->update([
                    'seguidores' => 0,
                    'inscritos_ou_alcance' => 0,
                ]);

            $ganhoAtual = $seguidoresAnteriores > 0
                ? max(0, $inscritosTotal - $seguidoresAnteriores)
                : 0;

            foreach ($porMes as $periodo => $lista) {
                $views = 0;
                $likes = 0;
                $comentarios = 0;

                foreach ($lista as $video) {
                    $views += $video['views'];
                    $likes += $video['likes'];
                    $comentarios += $video['comentarios'];
                }

                $ehAtual = $periodo === $periodoAtual;

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodo, 'plataforma' => 'youtube'],
                    [
                        'views' => $views,
                        'likes' => $likes,
                        'comentarios' => $comentarios,
                        'compartilhamentos' => 0,
                        'inscritos_ou_alcance' => $ehAtual ? $ganhoAtual : 0,
                        'seguidores' => $ehAtual ? $inscritosTotal : 0,
                    ],
                );

                self::gravarRanking('youtube', $periodo, $lista);
                $periodos++;
            }

            if (! isset($porMes[$periodoAtual])) {
                $existente = MarketingMetrica::query()
                    ->where('periodo', $periodoAtual)
                    ->where('plataforma', 'youtube')
                    ->first();

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodoAtual, 'plataforma' => 'youtube'],
                    [
                        'views' => (int) ($existente?->views ?? 0),
                        'likes' => (int) ($existente?->likes ?? 0),
                        'comentarios' => (int) ($existente?->comentarios ?? 0),
                        'compartilhamentos' => (int) ($existente?->compartilhamentos ?? 0),
                        'inscritos_ou_alcance' => $ganhoAtual,
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
     * @return array{periodos: int, aviso: string|null}
     */
    private static function sincronizarInstagram(): array
    {
        if (! self::instagramConfigurado()) {
            return [
                'periodos' => 0,
                'aviso' => 'Instagram ainda sem HC_INSTAGRAM_ACCESS_TOKEN / HC_INSTAGRAM_USER_ID.',
            ];
        }

        $token = (string) config('hc.marketing.instagram_access_token');
        $usuarioInformado = (string) config('hc.marketing.instagram_user_id');

        try {
            $usuario = self::resolverIgUserId($usuarioInformado, $token);
            $perfil = self::instagramGet($usuario, [
                'fields' => 'followers_count,media_count,username',
            ], $token);

            $seguidoresTotal = (int) ($perfil['followers_count'] ?? 0);
            $midias = self::midiasInstagram($usuario, $token);
            $porMes = self::agruparPorMes($midias);
            $periodoAtual = now()->format('Y-m');
            $periodos = 0;

            $periodoAnterior = Carbon::createFromFormat('Y-m', $periodoAtual)
                ->startOfMonth()
                ->subMonth()
                ->format('Y-m');
            $seguidoresAnteriores = (int) (MarketingMetrica::query()
                ->where('plataforma', 'instagram')
                ->where('periodo', $periodoAnterior)
                ->value('seguidores') ?? 0);

            if (
                $seguidoresAnteriores > 0
                && $seguidoresTotal > 0
                && $seguidoresAnteriores > max(500, $seguidoresTotal * 2)
            ) {
                $seguidoresAnteriores = 0;
            }

            MarketingMetrica::query()
                ->where('plataforma', 'instagram')
                ->update([
                    'seguidores' => 0,
                ]);

            $ganhoAtual = $seguidoresAnteriores > 0
                ? max(0, $seguidoresTotal - $seguidoresAnteriores)
                : 0;

            foreach ($porMes as $periodo => $lista) {
                $views = 0;
                $likes = 0;
                $comentarios = 0;
                $alcance = 0;

                foreach ($lista as $item) {
                    $views += $item['views'];
                    $likes += $item['likes'];
                    $comentarios += $item['comentarios'];
                    $alcance += $item['alcance'];
                }

                $ehAtual = $periodo === $periodoAtual;

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodo, 'plataforma' => 'instagram'],
                    [
                        'views' => $views,
                        'likes' => $likes,
                        'comentarios' => $comentarios,
                        'compartilhamentos' => 0,
                        'inscritos_ou_alcance' => $alcance > 0 ? $alcance : ($ehAtual ? $ganhoAtual : 0),
                        'seguidores' => $ehAtual ? $seguidoresTotal : 0,
                    ],
                );

                self::gravarRanking('instagram', $periodo, $lista);
                $periodos++;
            }

            if (! isset($porMes[$periodoAtual])) {
                $existente = MarketingMetrica::query()
                    ->where('periodo', $periodoAtual)
                    ->where('plataforma', 'instagram')
                    ->first();

                MarketingMetrica::query()->updateOrCreate(
                    ['periodo' => $periodoAtual, 'plataforma' => 'instagram'],
                    [
                        'views' => (int) ($existente?->views ?? 0),
                        'likes' => (int) ($existente?->likes ?? 0),
                        'comentarios' => (int) ($existente?->comentarios ?? 0),
                        'compartilhamentos' => (int) ($existente?->compartilhamentos ?? 0),
                        'inscritos_ou_alcance' => (int) ($existente?->inscritos_ou_alcance ?? $ganhoAtual),
                        'seguidores' => $seguidoresTotal,
                    ],
                );
                $periodos++;
            }

            return ['periodos' => $periodos, 'aviso' => null];
        } catch (Throwable $erro) {
            return [
                'periodos' => 0,
                'aviso' => 'Instagram: '.$erro->getMessage(),
            ];
        }
    }

    /**
     * Aceita IG User ID, Page ID ou ID inválido — tenta descobrir a conta business pelo token.
     */
    private static function resolverIgUserId(string $idInformado, string $token): string
    {
        // 1) Já é IG User?
        try {
            self::instagramGet($idInformado, [
                'fields' => 'username,followers_count',
            ], $token);

            return $idInformado;
        } catch (Throwable) {
            // continua
        }

        // 2) É Page ID com Instagram ligado?
        try {
            $pagina = self::instagramGet($idInformado, [
                'fields' => 'instagram_business_account',
            ], $token);
            $igId = (string) data_get($pagina, 'instagram_business_account.id', '');
            if ($igId !== '') {
                return $igId;
            }
        } catch (Throwable) {
            // continua
        }

        // 3) Descobre pelas Páginas que o token enxerga.
        $encontrado = self::descobrirIgUserNasPaginas($token, $idInformado);
        if ($encontrado !== null) {
            return $encontrado;
        }

        throw new \RuntimeException(
            'Não achei a conta Instagram business com este HC_INSTAGRAM_USER_ID. '
            .'No Graph API Explorer (com o mesmo token), rode me/accounts?fields=id,name,instagram_business_account '
            .'e use o id de instagram_business_account (geralmente começa com 178414…).'
        );
    }

    private static function descobrirIgUserNasPaginas(string $token, string $idInformado): ?string
    {
        try {
            $contas = self::instagramGet('me/accounts', [
                'fields' => 'id,name,instagram_business_account{id,username}',
                'limit' => 50,
            ], $token);
        } catch (Throwable) {
            return null;
        }

        $primeiro = null;

        foreach ($contas['data'] ?? [] as $conta) {
            if (! is_array($conta)) {
                continue;
            }

            $igId = (string) data_get($conta, 'instagram_business_account.id', '');
            if ($igId === '') {
                continue;
            }

            $pageId = (string) ($conta['id'] ?? '');
            $username = (string) data_get($conta, 'instagram_business_account.username', '');

            if (
                $igId === $idInformado
                || $pageId === $idInformado
                || strcasecmp($username, 'healthcarecontabilidade') === 0
            ) {
                return $igId;
            }

            $primeiro ??= $igId;
        }

        return $primeiro;
    }

    /**
     * @return list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, alcance: int, url: string}>
     */
    private static function midiasInstagram(string $usuario, string $token): array
    {
        $itens = [];
        $after = null;

        for ($pagina = 0; $pagina < 2; $pagina++) {
            $params = [
                'fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count',
                'limit' => 50,
            ];
            if (is_string($after) && $after !== '') {
                $params['after'] = $after;
            }

            $lista = self::instagramGet($usuario.'/media', $params, $token);

            foreach ($lista['data'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $publicado = (string) ($item['timestamp'] ?? '');
                if ($id === '' || $publicado === '') {
                    continue;
                }

                $insights = self::insightsMidiaInstagram($id, $token);
                $caption = trim((string) ($item['caption'] ?? ''));
                $titulo = $caption !== ''
                    ? mb_substr(preg_replace('/\s+/', ' ', $caption) ?? $caption, 0, 120)
                    : 'Publicação Instagram';

                $itens[] = [
                    'titulo' => $titulo,
                    'publicado_em' => Carbon::parse($publicado)->toDateString(),
                    'views' => $insights['views'],
                    'likes' => (int) ($item['like_count'] ?? 0),
                    'comentarios' => (int) ($item['comments_count'] ?? 0),
                    'alcance' => $insights['alcance'],
                    'url' => (string) ($item['permalink'] ?? config('hc.marketing.instagram_url')),
                ];
            }

            $after = data_get($lista, 'paging.cursors.after');
            if (! is_string($after) || $after === '') {
                break;
            }
        }

        return $itens;
    }

    /**
     * @return array{views: int, alcance: int}
     */
    private static function insightsMidiaInstagram(string $mediaId, string $token): array
    {
        try {
            $resposta = self::instagramGet($mediaId.'/insights', [
                'metric' => 'plays,impressions,reach',
            ], $token);

            $views = 0;
            $alcance = 0;

            foreach ($resposta['data'] ?? [] as $metrica) {
                if (! is_array($metrica)) {
                    continue;
                }
                $nome = (string) ($metrica['name'] ?? '');
                $valor = (int) data_get($metrica, 'values.0.value', 0);
                if ($nome === 'plays' || $nome === 'impressions') {
                    $views = max($views, $valor);
                }
                if ($nome === 'reach') {
                    $alcance = $valor;
                }
            }

            return ['views' => $views, 'alcance' => $alcance];
        } catch (Throwable) {
            // Insights exigem permissão/tipo de mídia; sem isso seguimos com likes/comentários.
            return ['views' => 0, 'alcance' => 0];
        }
    }

    /**
     * @param  list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>  $lista
     */
    private static function gravarRanking(
        string $plataforma,
        string $periodo,
        array $lista,
    ): void {
        $ranking = collect($lista)->sortByDesc('views')->values();
        if ($ranking->isEmpty()) {
            $ranking = collect($lista)->sortByDesc('likes')->values();
        }

        MarketingConteudo::query()
            ->where('plataforma', $plataforma)
            ->where('periodo', $periodo)
            ->update(['destaque' => false]);

        foreach ($ranking->take(5) as $indice => $item) {
            if (! is_array($item)) {
                continue;
            }

            MarketingConteudo::query()->updateOrCreate(
                [
                    'titulo' => $item['titulo'],
                    'plataforma' => $plataforma,
                    'periodo' => $periodo,
                ],
                [
                    'publicado_em' => $item['publicado_em'],
                    'views' => $item['views'],
                    'likes' => $item['likes'],
                    'comentarios' => $item['comentarios'],
                    'ganho_inscritos' => 0,
                    'ganho_seguidores' => 0,
                    'destaque' => $indice === 0,
                    'url' => $item['url'],
                ],
            );
        }
    }

    /**
     * @return list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>
     */
    private static function videosDoCanal(string $uploadsPlaylistId, string $chave): array
    {
        $videoIds = [];
        $pageToken = null;

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
     * @param  list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>  $itens
     * @return array<string, list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string}>>
     */
    private static function agruparPorMes(array $itens): array
    {
        $porMes = [];

        foreach ($itens as $item) {
            $periodo = Carbon::parse($item['publicado_em'])->format('Y-m');
            $porMes[$periodo][] = $item;
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
            throw new \RuntimeException(self::mensagemHttp($excecao), previous: $excecao);
        }
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private static function instagramGet(string $caminho, array $params, string $token): array
    {
        try {
            /** @var array<string, mixed> $json */
            $json = Http::connectTimeout(5)
                ->timeout(25)
                ->acceptJson()
                ->get(self::INSTAGRAM_BASE.'/'.ltrim($caminho, '/'), [...$params, 'access_token' => $token])
                ->throw()
                ->json();

            return $json;
        } catch (RequestException $excecao) {
            throw new \RuntimeException(self::mensagemHttp($excecao), previous: $excecao);
        }
    }

    private static function mensagemHttp(RequestException $excecao): string
    {
        $corpo = $excecao->response?->json();

        return is_array($corpo)
            ? (string) data_get($corpo, 'error.message', $excecao->getMessage())
            : $excecao->getMessage();
    }

    private static function instagramConfigurado(): bool
    {
        return (string) config('hc.marketing.instagram_access_token') !== ''
            && (string) config('hc.marketing.instagram_user_id') !== '';
    }
}
