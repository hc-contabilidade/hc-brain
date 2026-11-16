<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarMarketingIdeiaRequest;
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
     * Resumo do período: KPIs YouTube/Instagram, série de 6 meses e ideias abertas.
     */
    public function show(string $periodo): JsonResponse
    {
        $periodo = MarketingMetrica::query()->where('periodo', $periodo)->exists()
            ? $periodo
            : (MarketingMetrica::query()->orderByDesc('periodo')->value('periodo') ?? now()->format('Y-m'));

        $youtube = MarketingMetrica::query()
            ->where('periodo', $periodo)
            ->where('plataforma', 'youtube')
            ->first();
        $instagram = MarketingMetrica::query()
            ->where('periodo', $periodo)
            ->where('plataforma', 'instagram')
            ->first();

        return response()->json([
            'periodo' => $periodo,
            'youtube' => self::metrica($youtube),
            'instagram' => self::metrica($instagram),
            'serieYoutube' => self::serie('youtube', $periodo),
            'serieInstagram' => self::serie('instagram', $periodo),
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

    /** @return array<string, int> */
    private static function metrica(?MarketingMetrica $metrica): array
    {
        return [
            'views' => (int) ($metrica?->views ?? 0),
            'likes' => (int) ($metrica?->likes ?? 0),
            'comentarios' => (int) ($metrica?->comentarios ?? 0),
            'compartilhamentos' => (int) ($metrica?->compartilhamentos ?? 0),
            'inscritosOuAlcance' => (int) ($metrica?->inscritos_ou_alcance ?? 0),
        ];
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function serie(string $plataforma, string $periodo): array
    {
        $fim = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();
        $meses = [];

        for ($i = 5; $i >= 0; $i--) {
            $mes = $fim->copy()->subMonths($i);
            $chave = $mes->format('Y-m');
            $valor = (int) (MarketingMetrica::query()
                ->where('periodo', $chave)
                ->where('plataforma', $plataforma)
                ->value('views') ?? 0);

            $meses[] = [
                $mes->locale('pt_BR')->translatedFormat('M'),
                $valor,
            ];
        }

        return $meses;
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
