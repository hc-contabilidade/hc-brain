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
 * YouTube: Analytics API (Insights/OAuth) quando conectado; senão Data API key.
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
        } elseif (! self::instagramConfigurado()) {
            // YouTube real no ar: não deixar meses de Instagram do seeder parecerem oficiais.
            self::limparPlataforma('instagram');
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
        if (YouTubeAnalytics::conectada()) {
            return self::sincronizarYoutubeAnalytics();
        }

        $chave = (string) config('hc.marketing.youtube_api_key');
        $canal = (string) config('hc.marketing.youtube_channel_id');

        if ($chave === '' || $canal === '') {
            return [
                'periodos' => 0,
                'aviso' => 'YouTube Insights: conecte o Analytics em /marketing (OAuth) ou defina HC_YOUTUBE_API_KEY / HC_YOUTUBE_CHANNEL_ID.',
            ];
        }

        $resultado = self::sincronizarYoutubeDataApi($chave, $canal);
        if ($resultado['aviso'] === null) {
            $resultado['aviso'] = 'YouTube: sync pela Data API (views vitalícias por publicação). Para Insights do Studio, conecte o YouTube Analytics.';
        }

        return $resultado;
    }

    /**
     * Insights do canal (views/likes/etc. no período), como no YouTube Studio.
     *
     * @return array{periodos: int, aviso: string|null}
     */
    private static function sincronizarYoutubeAnalytics(): array
    {
        $conta = YouTubeAnalytics::contaAtiva();
        if ($conta === null) {
            return ['periodos' => 0, 'aviso' => 'YouTube Insights: nenhuma conta Analytics conectada.'];
        }

        try {
            $token = YouTubeAnalytics::tokenDeAcesso($conta);
            $channelId = (string) ($conta->channel_id ?: config('hc.marketing.youtube_channel_id'));
            if ($channelId === '') {
                $channelId = (string) (YouTubeAnalytics::descobrirChannelId($token) ?? '');
            }
            if ($channelId === '') {
                return ['periodos' => 0, 'aviso' => 'YouTube Insights: canal não identificado. Defina HC_YOUTUBE_CHANNEL_ID ou reconecte.'];
            }

            if ($conta->channel_id !== $channelId) {
                $conta->update(['channel_id' => $channelId, 'erro' => null]);
            }

            $agora = now();
            $fim = $agora->toDateString();
            $periodoAtual = $agora->format('Y-m');
            // dimensions=month: startDate e endDate precisam ser o 1º dia de um mês
            // (não o último). O mês corrente parcial vem sem essa dimension.
            $inicioHistorico = $agora->copy()->startOfMonth()->subMonths(11)->toDateString();
            $fimHistorico = $agora->copy()->startOfMonth()->subMonth()->toDateString(); // 1º do mês anterior
            $mesParcial = ! $agora->isSameDay($agora->copy()->endOfMonth());

            $mensais = $inicioHistorico <= $fimHistorico
                ? YouTubeAnalytics::metricasMensais($token, $channelId, $inicioHistorico, $fimHistorico)
                : [];

            if ($mesParcial) {
                $atual = YouTubeAnalytics::metricasPeriodo(
                    $token,
                    $channelId,
                    $agora->copy()->startOfMonth()->toDateString(),
                    $fim,
                );
                if ($atual !== null) {
                    $mensais[] = $atual;
                }
            } elseif ($inicioHistorico <= $agora->copy()->startOfMonth()->toDateString()) {
                $mensais = YouTubeAnalytics::metricasMensais(
                    $token,
                    $channelId,
                    $inicioHistorico,
                    $agora->copy()->startOfMonth()->toDateString(),
                );
            }

            $inscritosTotal = YouTubeAnalytics::inscritosTotais($token, $channelId);

            self::limparPlataforma('youtube');

            $periodos = 0;
            foreach ($mensais as $linha) {
                $ehAtual = $linha['periodo'] === $periodoAtual;
                MarketingMetrica::query()->create([
                    'periodo' => $linha['periodo'],
                    'plataforma' => 'youtube',
                    'views' => $linha['views'],
                    'likes' => $linha['likes'],
                    'comentarios' => $linha['comentarios'],
                    'compartilhamentos' => $linha['compartilhamentos'],
                    'inscritos_ou_alcance' => $linha['inscritos_ganhos'],
                    'seguidores' => $ehAtual ? $inscritosTotal : 0,
                ]);

                $inicioMes = Carbon::createFromFormat('Y-m', $linha['periodo'])->startOfMonth()->toDateString();
                $fimMes = Carbon::createFromFormat('Y-m', $linha['periodo'])->endOfMonth()->toDateString();
                if ($ehAtual) {
                    $fimMes = min($fimMes, $fim);
                }

                try {
                    $ranking = YouTubeAnalytics::rankingVideos($token, $channelId, $inicioMes, $fimMes, 10);
                    $meta = YouTubeAnalytics::metadadosVideos(
                        $token,
                        array_map(fn (array $item) => $item['video_id'], $ranking),
                    );

                    $lista = [];
                    foreach ($ranking as $item) {
                        $info = $meta[$item['video_id']] ?? null;
                        if ($info === null) {
                            continue;
                        }
                        $lista[] = [
                            'titulo' => $info['titulo'],
                            'publicado_em' => $info['publicado_em'],
                            'views' => $item['views'],
                            'likes' => $item['likes'],
                            'comentarios' => $item['comentarios'],
                            'formato' => $info['formato'],
                            'url' => $info['url'],
                        ];
                    }

                    self::gravarRanking('youtube', $linha['periodo'], $lista);
                } catch (Throwable) {
                    // 500 intermitente da Analytics no ranking não pode zerar o mês já gravado.
                }

                $periodos++;
            }

            if ($periodos === 0 || ! collect($mensais)->contains(fn (array $l) => $l['periodo'] === $periodoAtual)) {
                MarketingMetrica::query()->create([
                    'periodo' => $periodoAtual,
                    'plataforma' => 'youtube',
                    'views' => 0,
                    'likes' => 0,
                    'comentarios' => 0,
                    'compartilhamentos' => 0,
                    'inscritos_ou_alcance' => 0,
                    'seguidores' => $inscritosTotal,
                ]);
                $periodos++;
            }

            $conta->update(['sincronizada_em' => now(), 'erro' => null]);

            return ['periodos' => $periodos, 'aviso' => null];
        } catch (Throwable $erro) {
            $conta->update(['erro' => mb_substr($erro->getMessage(), 0, 240)]);

            return [
                'periodos' => 0,
                'aviso' => 'YouTube Insights: '.$erro->getMessage(),
            ];
        }
    }

    /**
     * Fallback: Data API key (views vitalícias dos uploads no mês de publicação).
     *
     * @return array{periodos: int, aviso: string|null}
     */
    private static function sincronizarYoutubeDataApi(string $chave, string $canal): array
    {
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

            $ganhoAtual = $seguidoresAnteriores > 0
                ? max(0, $inscritosTotal - $seguidoresAnteriores)
                : 0;

            self::limparPlataforma('youtube');

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

                MarketingMetrica::query()->create([
                    'periodo' => $periodo,
                    'plataforma' => 'youtube',
                    'views' => $views,
                    'likes' => $likes,
                    'comentarios' => $comentarios,
                    'compartilhamentos' => 0,
                    'inscritos_ou_alcance' => $ehAtual ? $ganhoAtual : 0,
                    'seguidores' => $ehAtual ? $inscritosTotal : 0,
                ]);

                self::gravarRanking('youtube', $periodo, $lista);
                $periodos++;
            }

            if (! isset($porMes[$periodoAtual])) {
                MarketingMetrica::query()->create([
                    'periodo' => $periodoAtual,
                    'plataforma' => 'youtube',
                    'views' => 0,
                    'likes' => 0,
                    'comentarios' => 0,
                    'compartilhamentos' => 0,
                    'inscritos_ou_alcance' => $ganhoAtual,
                    'seguidores' => $inscritosTotal,
                ]);
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
            [$usuario, $graphBase] = self::resolverIgUserId($usuarioInformado, $token);
            $perfil = self::instagramGet($usuario, [
                'fields' => 'followers_count,media_count,username',
            ], $token, $graphBase);

            $seguidoresTotal = (int) ($perfil['followers_count'] ?? 0);
            // Se Instagram Login não devolver followers_count, segue com mídia/insights.
            $midias = self::midiasInstagram($usuario, $token, $graphBase);
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

            $ganhoAtual = $seguidoresAnteriores > 0
                ? max(0, $seguidoresTotal - $seguidoresAnteriores)
                : 0;

            self::limparPlataforma('instagram');

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

                MarketingMetrica::query()->create([
                    'periodo' => $periodo,
                    'plataforma' => 'instagram',
                    'views' => $views,
                    'likes' => $likes,
                    'comentarios' => $comentarios,
                    'compartilhamentos' => 0,
                    'inscritos_ou_alcance' => $alcance > 0 ? $alcance : ($ehAtual ? $ganhoAtual : 0),
                    'seguidores' => $ehAtual ? $seguidoresTotal : 0,
                ]);

                self::gravarRanking('instagram', $periodo, $lista);
                $periodos++;
            }

            if (! isset($porMes[$periodoAtual])) {
                MarketingMetrica::query()->create([
                    'periodo' => $periodoAtual,
                    'plataforma' => 'instagram',
                    'views' => 0,
                    'likes' => 0,
                    'comentarios' => 0,
                    'compartilhamentos' => 0,
                    'inscritos_ou_alcance' => $ganhoAtual,
                    'seguidores' => $seguidoresTotal,
                ]);
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
     *
     * @return array{0: string, 1: string} [igUserId, graphBase]
     */
    private static function resolverIgUserId(string $idInformado, string $token): array
    {
        // 1) Já é IG User no Graph Facebook?
        try {
            self::instagramGet($idInformado, [
                'fields' => 'username,followers_count',
            ], $token);

            return [$idInformado, self::INSTAGRAM_BASE];
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
                return [$igId, self::INSTAGRAM_BASE];
            }
        } catch (Throwable) {
            // continua
        }

        // 3) Descobre pelas Páginas que o token enxerga.
        $encontrado = self::descobrirIgUserNasPaginas($token, $idInformado);
        if ($encontrado !== null) {
            return [$encontrado, self::INSTAGRAM_BASE];
        }

        // 4) Token de Instagram Login (graph.instagram.com)?
        $igLogin = self::descobrirIgUserInstagramLogin($token);
        if ($igLogin !== null) {
            return [$igLogin, 'https://graph.instagram.com/v21.0'];
        }

        throw new \RuntimeException(self::diagnosticoInstagram($token, $idInformado));
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

    private static function descobrirIgUserInstagramLogin(string $token): ?string
    {
        try {
            /** @var array<string, mixed> $me */
            $me = Http::connectTimeout(5)
                ->timeout(20)
                ->acceptJson()
                ->get('https://graph.instagram.com/v21.0/me', [
                    'fields' => 'user_id,username,account_type',
                    'access_token' => $token,
                ])
                ->throw()
                ->json();

            $id = (string) ($me['user_id'] ?? $me['id'] ?? '');

            return $id !== '' ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function diagnosticoInstagram(string $token, string $idInformado): string
    {
        $detalhes = ['id_informado='.$idInformado];

        try {
            $me = self::instagramGet('me', ['fields' => 'id,name'], $token);
            $detalhes[] = 'facebook_user='.(string) ($me['id'] ?? '?');
        } catch (Throwable $erro) {
            $detalhes[] = 'facebook_me='.$erro->getMessage();
        }

        try {
            $contas = self::instagramGet('me/accounts', [
                'fields' => 'id,name,instagram_business_account',
                'limit' => 50,
            ], $token);
            $lista = is_array($contas['data'] ?? null) ? $contas['data'] : [];
            $comIg = 0;
            foreach ($lista as $conta) {
                if (is_array($conta) && data_get($conta, 'instagram_business_account.id')) {
                    $comIg++;
                }
            }
            $detalhes[] = 'paginas='.count($lista);
            $detalhes[] = 'paginas_com_instagram='.$comIg;
        } catch (Throwable $erro) {
            $detalhes[] = 'me/accounts='.$erro->getMessage();
        }

        return 'Token/ID do Instagram não enxergam a conta business. '
            .'Gere de novo o token no Explorer com permissões pages_show_list, instagram_basic, instagram_manage_insights, pages_read_engagement; '
            .'depois rode me/accounts?fields=id,name,instagram_business_account e use instagram_business_account.id. '
            .'Diagnóstico: '.implode('; ', $detalhes);
    }

    /**
     * @return list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, alcance: int, url: string}>
     */
    private static function midiasInstagram(string $usuario, string $token, string $graphBase = self::INSTAGRAM_BASE): array
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

            $lista = self::instagramGet($usuario.'/media', $params, $token, $graphBase);

            foreach ($lista['data'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $publicado = (string) ($item['timestamp'] ?? '');
                if ($id === '' || $publicado === '') {
                    continue;
                }

                $insights = self::insightsMidiaInstagram($id, $token, $graphBase);
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
                    'formato' => 'post',
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
    private static function insightsMidiaInstagram(string $mediaId, string $token, string $graphBase = self::INSTAGRAM_BASE): array
    {
        try {
            $resposta = self::instagramGet($mediaId.'/insights', [
                'metric' => 'plays,impressions,reach',
            ], $token, $graphBase);

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
     * @param  list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string, formato?: string}>  $lista
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

        $formatoPadrao = $plataforma === 'instagram' ? 'post' : 'video';

        foreach ($ranking->take(10) as $indice => $item) {
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
                    'formato' => (string) ($item['formato'] ?? $formatoPadrao),
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
     * Busca uploads recentes (vídeos longos + Shorts). A playlist de uploads
     * do canal inclui Shorts; sem isso o ranking só via “Videos” omitiria cortes.
     *
     * @return list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string, formato: string}>
     */
    private static function videosDoCanal(string $uploadsPlaylistId, string $chave): array
    {
        $videoIds = [];
        $pageToken = null;

        // Até 400 uploads (8×50): cobre Shorts antigos ainda relevantes por views.
        for ($pagina = 0; $pagina < 8; $pagina++) {
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
                'part' => 'snippet,statistics,contentDetails',
                'id' => implode(',', $lote),
            ], $chave);

            foreach ($detalhe['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['id'] ?? '');
                $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
                $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
                $contentDetails = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
                $publicado = (string) ($snippet['publishedAt'] ?? '');
                if ($id === '' || $publicado === '') {
                    continue;
                }

                $titulo = mb_substr((string) ($snippet['title'] ?? 'Sem título'), 0, 255);
                $descricao = (string) ($snippet['description'] ?? '');
                $duracao = self::duracaoEmSegundos((string) ($contentDetails['duration'] ?? ''));
                $ehShort = self::eYoutubeShort($titulo, $descricao, $duracao);

                $videos[] = [
                    'titulo' => $titulo,
                    'publicado_em' => Carbon::parse($publicado)->toDateString(),
                    'views' => (int) ($statistics['viewCount'] ?? 0),
                    'likes' => (int) ($statistics['likeCount'] ?? 0),
                    'comentarios' => (int) ($statistics['commentCount'] ?? 0),
                    'formato' => $ehShort ? 'short' : 'video',
                    'url' => $ehShort
                        ? 'https://www.youtube.com/shorts/'.$id
                        : 'https://www.youtube.com/watch?v='.$id,
                ];
            }
        }

        return $videos;
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

    /**
     * Shorts na Data API não têm flag oficial: usa duração (até 3 min) ou #shorts.
     */
    private static function eYoutubeShort(string $titulo, string $descricao, int $duracaoSegundos): bool
    {
        $texto = mb_strtolower($titulo.' '.$descricao);
        if (str_contains($texto, '#shorts') || str_contains($texto, '#short')) {
            return true;
        }

        return $duracaoSegundos > 0 && $duracaoSegundos <= 180;
    }

    /**
     * @param  list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string, formato?: string}>  $itens
     * @return array<string, list<array{titulo: string, publicado_em: string, views: int, likes: int, comentarios: int, url: string, formato?: string}>>
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
    private static function instagramGet(
        string $caminho,
        array $params,
        string $token,
        string $graphBase = self::INSTAGRAM_BASE,
    ): array {
        try {
            /** @var array<string, mixed> $json */
            $json = Http::connectTimeout(5)
                ->timeout(25)
                ->acceptJson()
                ->get(rtrim($graphBase, '/').'/'.ltrim($caminho, '/'), [...$params, 'access_token' => $token])
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

    private static function limparPlataforma(string $plataforma): void
    {
        MarketingMetrica::query()->where('plataforma', $plataforma)->delete();
        MarketingConteudo::query()->where('plataforma', $plataforma)->delete();
    }
}
