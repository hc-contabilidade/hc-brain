<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IndicadorComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ComercialController extends Controller
{
    /**
     * Os períodos que existem na base, do mais recente para o mais antigo.
     * O filtro da tela é montado a partir daqui: fechar mais um mês passa a
     * aparecer no select sem ninguém editar a view.
     */
    public function index(): JsonResponse
    {
        $periodos = IndicadorComercial::orderByDesc('periodo')
            ->pluck('periodo')
            ->map(fn (string $periodo) => [
                'id' => $periodo,
                'rotulo' => self::rotulo($periodo),
            ]);

        return response()->json($periodos);
    }

    /** "2026-09" vira "Setembro 2026" — é assim que se lê um mês. */
    private static function rotulo(string $periodo): string
    {
        [$ano, $mes] = explode('-', $periodo);

        $nome = Carbon::create((int) $ano, (int) $mes, 1)
            ->locale('pt_BR')
            ->translatedFormat('F');

        return ucfirst($nome)." {$ano}";
    }

    /**
     * O período vem de um `<select>`, então um valor desconhecido cai no mais
     * recente em vez de derrubar a tela.
     */
    public function show(string $periodo): JsonResponse
    {
        $indicadores = IndicadorComercial::find($periodo)
            ?? IndicadorComercial::orderByDesc('periodo')->firstOrFail();

        // O mês anterior é o que existe antes deste na base, e não o mês do
        // calendário: se janeiro não foi fechado, a comparação é com o último
        // que foi. Sem nenhum anterior, não há variação a mostrar.
        $anterior = IndicadorComercial::where('periodo', '<', $indicadores->periodo)
            ->orderByDesc('periodo')
            ->first();

        return response()->json([
            'periodo' => $indicadores->periodo,
            'leadsAnteriores' => $anterior?->leads,
            'leads' => $indicadores->leads,
            'qualified' => $indicadores->qualified,
            'meetings' => $indicadores->meetings,
            'meetingsDone' => $indicadores->meetings_done ?? 0,
            'proposals' => $indicadores->proposals,
            'closed' => $indicadores->closed,
            'abertura' => $indicadores->abertura ?? 0,
            'transferencia' => $indicadores->transferencia ?? 0,
            'rebotes' => $indicadores->rebotes ?? 0,
            'revenue' => $indicadores->revenue,
            'averageTicket' => $indicadores->average_ticket,
            'inProcess' => $indicadores->in_process,
            'monthly' => $indicadores->monthly,
            'origins' => $indicadores->origins,
            'sources' => $indicadores->sources ?? [],
            'funnel' => $indicadores->funnel ?? [],
            'losses' => $indicadores->losses,
            'sales' => $indicadores->sales,
            'team' => $indicadores->team,
        ]);
    }
}
