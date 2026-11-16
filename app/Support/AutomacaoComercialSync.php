<?php

namespace App\Support;

use App\Models\IndicadorComercial;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Monta os indicadores do Comercial a partir do CRM do automacao-2-hc
 * (Painel de Vendas: commercial_leads / meetings / proposals).
 *
 * Vendas são contagens (abertura/transferência), não receita em R$.
 */
final class AutomacaoComercialSync
{
    /** @var list<string> */
    private const FUNIL_STAGES = [
        'novo',
        'conversacao',
        'reuniao_agendada',
        'reuniao_realizada',
        'rebote',
        'projeto_futuro',
        'pausado',
        'perdido',
        'inadequado',
        'fechado',
    ];

    /** @var array<string, string> */
    private const CORES = [
        'organico' => '#6178ef',
        'ativo' => '#4e92dc',
        'pago' => '#38aa86',
        'indicacao' => '#6178ef',
        'redes_sociais' => '#9b82dc',
        'evento' => '#d05e75',
        'prospeccao' => '#6f8199',
        'parceria' => '#d2a34f',
        'whatsapp' => '#38aa86',
        'instagram' => '#9b82dc',
        'site' => '#d2a34f',
        'default' => '#6f8199',
    ];

    /**
     * @return array{periodos: int}
     */
    public static function sincronizar(ConnectionInterface $origem): array
    {
        $usuarios = self::usuarios($origem);
        $leads = self::leads($origem);
        $meetings = self::meetings($origem);
        $proposals = self::proposals($origem);

        $periodos = self::periodosAlvo($leads, $meetings);
        $inProcess = $leads->filter(fn (object $lead) => ! in_array($lead->stage, ['fechado', 'perdido', 'inadequado'], true))->count();

        foreach ($periodos as $periodo) {
            IndicadorComercial::query()->updateOrCreate(
                ['periodo' => $periodo],
                self::agregar($periodo, $leads, $meetings, $proposals, $usuarios, $inProcess),
            );
        }

        return ['periodos' => $periodos->count()];
    }

    /**
     * @param  Collection<int, object>  $leads
     * @param  Collection<int, object>  $meetings
     * @param  Collection<int, object>  $proposals
     * @param  Collection<int|string, string>  $usuarios
     * @return array<string, mixed>
     */
    private static function agregar(
        string $periodo,
        Collection $leads,
        Collection $meetings,
        Collection $proposals,
        Collection $usuarios,
        int $inProcess,
    ): array {
        $entrados = $leads->filter(fn (object $lead) => $lead->entered_periodo === $periodo);
        $fechados = $leads->filter(fn (object $lead) => $lead->stage === 'fechado' && $lead->closed_periodo === $periodo);
        $reunioes = $meetings->filter(fn (object $m) => $m->periodo === $periodo);
        $propostas = $proposals->filter(fn (object $p) => $p->periodo === $periodo && $p->status === 'enviada');

        $abertura = $fechados->where('type', 'abertura')->count();
        $transferencia = $fechados->where('type', 'transferencia')->count();
        $qualified = $entrados->filter(fn (object $lead) => ! in_array($lead->stage, ['novo', 'perdido'], true))->count();
        $rebotes = $entrados->filter(fn (object $lead) => $lead->is_rebote || $lead->stage === 'rebote')->count();

        $perdidos = $leads->filter(fn (object $lead) => $lead->stage === 'perdido' && (
            $lead->closed_periodo === $periodo || $lead->entered_periodo === $periodo
        ));

        return [
            'leads' => $entrados->count(),
            'qualified' => $qualified,
            'meetings' => $reunioes->count(),
            'meetings_done' => $reunioes->filter(fn (object $m) => $m->status === 'realizada' || $m->is_done)->count(),
            'proposals' => $propostas->count(),
            'closed' => $fechados->count(),
            'abertura' => $abertura,
            'transferencia' => $transferencia,
            'rebotes' => $rebotes,
            'revenue' => 0,
            'average_ticket' => 0,
            'in_process' => $inProcess,
            'monthly' => self::serieMensal($periodo, $leads),
            'origins' => self::contagemRotulada($entrados, 'origin'),
            'sources' => self::contagemRotulada($entrados, 'source'),
            'funnel' => self::funil($entrados->isEmpty() ? $leads : $entrados),
            'losses' => self::perdas($perdidos),
            'sales' => self::vendas($fechados, $usuarios),
            'team' => self::equipe($entrados, $fechados, $usuarios),
        ];
    }

    /**
     * @param  Collection<int, object>  $leads
     * @param  Collection<int, object>  $meetings
     * @return Collection<int, string>
     */
    private static function periodosAlvo(Collection $leads, Collection $meetings): Collection
    {
        return $leads->pluck('entered_periodo')
            ->merge($leads->pluck('closed_periodo'))
            ->merge($meetings->pluck('periodo'))
            ->push(now()->format('Y-m'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @param  Collection<int, object>  $leads
     * @return list<array{0: string, 1: int}>
     */
    private static function serieMensal(string $periodo, Collection $leads): array
    {
        $fim = Carbon::createFromFormat('Y-m', $periodo)->startOfMonth();
        $meses = [];

        for ($i = 5; $i >= 0; $i--) {
            $mes = $fim->copy()->subMonths($i);
            $chave = $mes->format('Y-m');
            $meses[] = [
                $mes->locale('pt_BR')->translatedFormat('M'),
                $leads->where('entered_periodo', $chave)->count(),
            ];
        }

        return $meses;
    }

    /**
     * @param  Collection<int, object>  $itens
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private static function contagemRotulada(Collection $itens, string $campo): array
    {
        return $itens
            ->groupBy(fn (object $item) => self::chave($item->{$campo} ?? null))
            ->map(fn (Collection $grupo, string $chave) => [
                self::rotulo($chave),
                $grupo->count(),
                self::CORES[$chave] ?? self::CORES['default'],
            ])
            ->sortByDesc(fn (array $linha) => $linha[1])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $leads
     * @return list<array{0: string, 1: int}>
     */
    private static function funil(Collection $leads): array
    {
        $porStage = $leads->groupBy('stage')->map->count();

        $linhas = [];
        foreach (self::FUNIL_STAGES as $stage) {
            $linhas[] = [self::rotulo($stage), (int) ($porStage[$stage] ?? 0)];
        }

        foreach ($porStage as $stage => $count) {
            if (! in_array($stage, self::FUNIL_STAGES, true)) {
                $linhas[] = [self::rotulo((string) $stage), (int) $count];
            }
        }

        return $linhas;
    }

    /**
     * @param  Collection<int, object>  $perdidos
     * @return list<array{0: string, 1: int}>
     */
    private static function perdas(Collection $perdidos): array
    {
        return $perdidos
            ->groupBy(function (object $lead) {
                $motivo = trim((string) ($lead->loss_reason ?? ''));

                return $motivo === '' ? 'Sem motivo' : $motivo;
            })
            ->map(fn (Collection $grupo, string $motivo) => [$motivo, $grupo->count()])
            ->sortByDesc(fn (array $linha) => $linha[1])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $fechados
     * @param  Collection<int|string, string>  $usuarios
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function vendas(Collection $fechados, Collection $usuarios): array
    {
        return $fechados
            ->sortByDesc('closed_at')
            ->take(20)
            ->map(fn (object $lead) => [
                (string) ($lead->name ?: 'Lead #'.$lead->id),
                $usuarios->get($lead->assigned_to, '—'),
                self::rotulo((string) ($lead->type ?: 'abertura')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $entrados
     * @param  Collection<int, object>  $fechados
     * @param  Collection<int|string, string>  $usuarios
     * @return list<array{0: string, 1: int, 2: int, 3: float, 4: int}>
     */
    private static function equipe(Collection $entrados, Collection $fechados, Collection $usuarios): array
    {
        $ids = $entrados->pluck('assigned_to')->merge($fechados->pluck('assigned_to'))->filter()->unique();

        return $ids->map(function ($userId) use ($entrados, $fechados, $usuarios) {
            $leads = $entrados->where('assigned_to', $userId)->count();
            $vendas = $fechados->where('assigned_to', $userId)->count();
            $conversao = $leads > 0 ? round(($vendas / $leads) * 100, 1) : 0.0;

            return [
                $usuarios->get($userId, 'Sem responsável'),
                $leads,
                $vendas,
                $conversao,
                0,
            ];
        })
            ->sortByDesc(fn (array $linha) => $linha[2])
            ->values()
            ->all();
    }

    /** @return Collection<int, object> */
    private static function leads(ConnectionInterface $origem): Collection
    {
        return $origem->table('commercial_leads')
            ->whereNull('deleted_at')
            ->get([
                'id', 'name', 'stage', 'type', 'origin', 'source', 'channel',
                'loss_reason', 'assigned_to', 'is_rebote', 'entered_at', 'closed_at', 'created_at',
            ])
            ->map(function (object $lead) {
                $entrada = self::periodoDe($lead->entered_at ?? $lead->created_at);
                $lead->entered_periodo = $entrada;
                $lead->closed_periodo = self::periodoDe($lead->closed_at);
                $lead->is_rebote = (bool) $lead->is_rebote;

                return $lead;
            });
    }

    /** @return Collection<int, object> */
    private static function meetings(ConnectionInterface $origem): Collection
    {
        return $origem->table('commercial_meetings')
            ->whereNull('deleted_at')
            ->get(['id', 'scheduled_at', 'status', 'is_done', 'is_cancelled'])
            ->map(function (object $meeting) {
                $meeting->periodo = self::periodoDe($meeting->scheduled_at);
                $meeting->is_done = (bool) $meeting->is_done;

                return $meeting;
            })
            ->filter(fn (object $meeting) => $meeting->periodo !== null);
    }

    /** @return Collection<int, object> */
    private static function proposals(ConnectionInterface $origem): Collection
    {
        return $origem->table('commercial_proposals')
            ->whereNull('deleted_at')
            ->get(['id', 'status', 'created_at'])
            ->map(function (object $proposal) {
                $proposal->periodo = self::periodoDe($proposal->created_at);

                return $proposal;
            })
            ->filter(fn (object $proposal) => $proposal->periodo !== null);
    }

    /** @return Collection<int|string, string> */
    private static function usuarios(ConnectionInterface $origem): Collection
    {
        if (! self::temTabela($origem, 'users')) {
            return collect();
        }

        return $origem->table('users')->pluck('name', 'id');
    }

    private static function temTabela(ConnectionInterface $origem, string $tabela): bool
    {
        try {
            $origem->table($tabela)->limit(1)->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function periodoDe(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse((string) $valor)->format('Y-m');
    }

    private static function chave(?string $valor): string
    {
        $limpo = mb_strtolower(trim((string) $valor));

        if ($limpo === '') {
            return 'sem_dado';
        }

        return str_replace([' ', '-'], '_', $limpo);
    }

    private static function rotulo(string $chave): string
    {
        $especiais = [
            'novo' => 'Novo',
            'conversacao' => 'Conversação',
            'reuniao_agendada' => 'Reunião agendada',
            'reuniao_realizada' => 'Reunião realizada',
            'rebote' => 'Rebote',
            'projeto_futuro' => 'Projeto futuro',
            'pausado' => 'Pausado',
            'perdido' => 'Perdido',
            'inadequado' => 'Inadequado',
            'fechado' => 'Fechado',
            'abertura' => 'Abertura',
            'transferencia' => 'Transferência',
            'organico' => 'Orgânico',
            'ativo' => 'Ativo',
            'pago' => 'Pago',
            'indicacao' => 'Indicação',
            'redes_sociais' => 'Redes Sociais',
            'prospeccao' => 'Prospecção',
            'parceria' => 'Parceria',
            'sem_dado' => 'Sem dado',
            'sem_motivo' => 'Sem motivo',
        ];

        if (isset($especiais[$chave])) {
            return $especiais[$chave];
        }

        return str_replace('_', ' ', mb_convert_case($chave, MB_CASE_TITLE, 'UTF-8'));
    }
}
