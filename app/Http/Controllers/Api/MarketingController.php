<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarMarketingIdeiaRequest;
use App\Models\MarketingConteudo;
use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class MarketingController extends Controller
{
    /**
     * Períodos disponíveis (a partir das métricas), do mais recente ao mais antigo.
     */
    public function periodos(): JsonResponse
    {
        $periodos = MarketingMetrica::query()
            ->select('periodo')
            ->distinct()
            ->orderByDesc('periodo')
            ->pluck('periodo')
            ->map(fn (string $periodo) => [
                'id' => $periodo,
                'rotulo' => self::rotulo($periodo),
            ]);

        return response()->json($periodos);
    }

    /**
     * Resumo do período: KPIs, séries, destaque de conteúdo e ideias abertas.
     */
    public function show(string $periodo): JsonResponse
    {
        $periodo = MarketingMetrica::query()->where('periodo', $periodo)->exists()
            ? $periodo
            : (MarketingMetrica::query()->orderByDesc('periodo')->value('periodo') ?? now()->format('Y-m'));

        $anterior = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth()->subMonth()->format('Y-m');

        $youtube = MarketingMetrica::query()
            ->where('periodo', $periodo)
            ->where('plataforma', 'youtube')
            ->first();
        $instagram = MarketingMetrica::query()
            ->where('periodo', $periodo)
            ->where('plataforma', 'instagram')
            ->first();
        $youtubeAnterior = MarketingMetrica::query()
            ->where('periodo', $anterior)
            ->where('plataforma', 'youtube')
            ->first();
        $instagramAnterior = MarketingMetrica::query()
            ->where('periodo', $anterior)
            ->where('plataforma', 'instagram')
            ->first();

        return response()->json([
            'periodo' => $periodo,
            'canais' => [
                'youtube' => (string) config('hc.marketing.youtube_url'),
                'instagram' => (string) config('hc.marketing.instagram_url'),
            ],
            'youtube' => self::metricaYoutube($youtube, $youtubeAnterior),
            'instagram' => self::metricaInstagram($instagram, $instagramAnterior),
            'serieYoutube' => self::serie('youtube', $periodo),
            'serieInstagram' => self::serie('instagram', $periodo),
            'videosYoutube' => self::videos('youtube', $periodo),
            'destaqueYoutube' => self::destaque('youtube', $periodo),
            'destaqueInstagram' => self::destaque('instagram', $periodo),
            'ideias' => MarketingIdeia::query()
                ->whereIn('status', ['rascunho', 'aprovada'])
                ->orderBy('ordem')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (MarketingIdeia $ideia) => self::ideia($ideia))
                ->values(),
        ]);
    }

    public function ideias(): JsonResponse
    {
        $ideias = MarketingIdeia::query()
            ->orderBy('ordem')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MarketingIdeia $ideia) => self::ideia($ideia))
            ->values();

        return response()->json($ideias);
    }

    public function storeIdeia(SalvarMarketingIdeiaRequest $request): JsonResponse
    {
        $ideia = MarketingIdeia::query()->create([
            ...$request->paraOBanco(),
            'ordem' => (int) MarketingIdeia::query()->max('ordem') + 1,
        ]);

        return response()->json(self::ideia($ideia), 201);
    }

    public function updateIdeia(SalvarMarketingIdeiaRequest $request, MarketingIdeia $ideia): JsonResponse
    {
        $ideia->update($request->paraOBanco());

        return response()->json(self::ideia($ideia->fresh()));
    }

    public function destroyIdeia(MarketingIdeia $ideia): Response
    {
        $ideia->delete();

        return response()->noContent();
    }

    private static function rotulo(string $periodo): string
    {
        [$ano, $mes] = explode('-', $periodo);

        $nome = Carbon::create((int) $ano, (int) $mes, 1)
            ->locale('pt_BR')
            ->translatedFormat('F');

        return ucfirst($nome)." {$ano}";
    }

    /** @return array<string, mixed> */
    private static function metricaYoutube(?MarketingMetrica $atual, ?MarketingMetrica $anterior): array
    {
        $ganho = (int) ($atual?->inscritos_ou_alcance ?? 0);
        $ganhoAnterior = (int) ($anterior?->inscritos_ou_alcance ?? 0);
        $views = (int) ($atual?->views ?? 0);
        $viewsAnterior = (int) ($anterior?->views ?? 0);
        $total = (int) ($atual?->seguidores ?? 0);
        $totalAnterior = (int) ($anterior?->seguidores ?? 0);
        // Saldo líquido de audiência entre snapshots (subidas e quedas).
        $saldo = $totalAnterior > 0 ? $total - $totalAnterior : $ganho;

        return [
            'views' => $views,
            'likes' => (int) ($atual?->likes ?? 0),
            'comentarios' => (int) ($atual?->comentarios ?? 0),
            'compartilhamentos' => (int) ($atual?->compartilhamentos ?? 0),
            'inscritosNovos' => max(0, $saldo),
            'inscritosPerdidos' => max(0, -$saldo),
            'saldoInscritos' => $saldo,
            'inscritosTotal' => $total,
            'variacaoViews' => $views - $viewsAnterior,
            'variacaoInscritos' => $ganho - $ganhoAnterior,
            'variacaoAudiencia' => $saldo,
            'engajamento' => $views > 0
                ? round((((int) ($atual?->likes ?? 0)) + ((int) ($atual?->comentarios ?? 0))) / $views, 4)
                : 0,
            // Compatível com o contrato antigo da tela.
            'inscritosOuAlcance' => $ganho,
        ];
    }

    /** @return array<string, mixed> */
    private static function metricaInstagram(?MarketingMetrica $atual, ?MarketingMetrica $anterior): array
    {
        $alcance = (int) ($atual?->inscritos_ou_alcance ?? 0);
        $alcanceAnterior = (int) ($anterior?->inscritos_ou_alcance ?? 0);
        $seguidores = (int) ($atual?->seguidores ?? 0);
        $seguidoresAnterior = (int) ($anterior?->seguidores ?? 0);
        $views = (int) ($atual?->views ?? 0);
        $viewsAnterior = (int) ($anterior?->views ?? 0);

        return [
            'views' => $views,
            'likes' => (int) ($atual?->likes ?? 0),
            'comentarios' => (int) ($atual?->comentarios ?? 0),
            'compartilhamentos' => (int) ($atual?->compartilhamentos ?? 0),
            'alcance' => $alcance,
            'seguidores' => $seguidores,
            'variacaoViews' => $views - $viewsAnterior,
            'variacaoAlcance' => $alcance - $alcanceAnterior,
            'variacaoSeguidores' => $seguidores - $seguidoresAnterior,
            'inscritosOuAlcance' => $alcance,
        ];
    }

    /**
     * @return list<array{rotulo: string, views: int, ganho: int, audiencia: int, saldo: int}>
     */
    private static function serie(string $plataforma, string $periodo): array
    {
        $fim = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();
        $meses = [];
        $audienciaAnterior = null;

        for ($i = 5; $i >= 0; $i--) {
            $mes = $fim->copy()->subMonths($i);
            $chave = $mes->format('Y-m');
            $linha = MarketingMetrica::query()
                ->where('periodo', $chave)
                ->where('plataforma', $plataforma)
                ->first();

            $audiencia = (int) ($linha?->seguidores ?? 0);
            $ganho = (int) ($linha?->inscritos_ou_alcance ?? 0);
            $saldo = $audienciaAnterior !== null && $audienciaAnterior > 0 && $audiencia > 0
                ? $audiencia - $audienciaAnterior
                : $ganho;

            $meses[] = [
                'rotulo' => $mes->locale('pt_BR')->translatedFormat('M'),
                'views' => (int) ($linha?->views ?? 0),
                'ganho' => $ganho,
                'audiencia' => $audiencia,
                'saldo' => $saldo,
            ];

            if ($audiencia > 0) {
                $audienciaAnterior = $audiencia;
            }
        }

        return $meses;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function videos(string $plataforma, string $periodo): array
    {
        $lista = MarketingConteudo::query()
            ->where('plataforma', $plataforma)
            ->where('periodo', $periodo)
            ->orderByDesc('views')
            ->limit(5)
            ->get();

        if ($lista->isEmpty()) {
            $lista = MarketingConteudo::query()
                ->where('plataforma', $plataforma)
                ->orderByDesc('views')
                ->limit(5)
                ->get();
        }

        return $lista
            ->map(fn (MarketingConteudo $conteudo) => [
                'titulo' => $conteudo->titulo,
                'periodo' => $conteudo->periodo,
                'publicadoEm' => $conteudo->publicado_em?->format('Y-m-d'),
                'views' => (int) $conteudo->views,
                'likes' => (int) $conteudo->likes,
                'comentarios' => (int) $conteudo->comentarios,
                'destaque' => (bool) $conteudo->destaque,
                'url' => $conteudo->url,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private static function destaque(string $plataforma, string $periodo): ?array
    {
        $conteudo = MarketingConteudo::query()
            ->where('plataforma', $plataforma)
            ->where('periodo', $periodo)
            ->orderByDesc('destaque')
            ->orderByDesc($plataforma === 'youtube' ? 'ganho_inscritos' : 'ganho_seguidores')
            ->orderByDesc('views')
            ->first();

        if ($conteudo === null) {
            $conteudo = MarketingConteudo::query()
                ->where('plataforma', $plataforma)
                ->orderByDesc('destaque')
                ->orderByDesc($plataforma === 'youtube' ? 'ganho_inscritos' : 'ganho_seguidores')
                ->orderByDesc('views')
                ->first();
        }

        if ($conteudo === null) {
            return null;
        }

        return [
            'titulo' => $conteudo->titulo,
            'periodo' => $conteudo->periodo,
            'publicadoEm' => $conteudo->publicado_em?->format('Y-m-d'),
            'views' => (int) $conteudo->views,
            'likes' => (int) $conteudo->likes,
            'comentarios' => (int) $conteudo->comentarios,
            'ganhoInscritos' => (int) $conteudo->ganho_inscritos,
            'ganhoSeguidores' => (int) $conteudo->ganho_seguidores,
            'destaque' => (bool) $conteudo->destaque,
            'url' => $conteudo->url,
        ];
    }

    /** @return array<string, mixed> */
    private static function ideia(MarketingIdeia $ideia): array
    {
        return [
            'id' => $ideia->id,
            'titulo' => $ideia->titulo,
            'descricao' => $ideia->descricao,
            'plataforma' => $ideia->plataforma,
            'status' => $ideia->status,
            'responsavel' => $ideia->responsavel,
            'prioridade' => $ideia->prioridade,
        ];
    }
}
