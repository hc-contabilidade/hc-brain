<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\EmpresaFinanceiro;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monta o financeiro do Brain a partir dos honorários (`charges`) do automacao-2-hc.
 *
 * Cada cobrança já é o título que a automação manda ao Omie; não depende de
 * app_secret. O 1º honorário e o atual usam só categorias de honorário; o
 * total e as movimentações incluem as demais cobranças da empresa.
 */
final class AutomacaoHonorariosSync
{
    /** @var list<string> */
    private const RECEBIDOS = ['RECEBIDO'];

    /** @var list<string> */
    private const ABERTOS = ['AVENCER', 'ATRASADO', 'VENCEHOJE'];

    /**
     * @param  Collection<int, Empresa>  $empresas  empresas com automacao_id
     * @return array{atualizadas: int, sem_cobranca: int}
     */
    public static function sincronizarVarias(Collection $empresas, ConnectionInterface $origem): array
    {
        $ids = $empresas->pluck('automacao_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return ['atualizadas' => 0, 'sem_cobranca' => 0];
        }

        $cobrancas = self::cobrancas($origem, $ids);
        $regimes = self::regimes($origem, $ids);
        $inicios = self::inicios($origem, $ids);

        $atualizadas = 0;
        $semCobranca = 0;

        foreach ($empresas as $empresa) {
            $companyId = (int) $empresa->automacao_id;
            $doCliente = $cobrancas->get($companyId, collect());

            if ($doCliente->isEmpty()) {
                $semCobranca++;

                continue;
            }

            DB::transaction(function () use ($empresa, $doCliente, $regimes, $inicios, $companyId) {
                self::gravar(
                    $empresa,
                    $doCliente,
                    $regimes[$companyId] ?? 'Simples Nacional',
                    $inicios[$companyId] ?? null,
                );
            });

            $atualizadas++;
        }

        return ['atualizadas' => $atualizadas, 'sem_cobranca' => $semCobranca];
    }

    public static function sincronizar(Empresa $empresa, ConnectionInterface $origem): ?EmpresaFinanceiro
    {
        if ($empresa->automacao_id === null) {
            return null;
        }

        $resultado = self::sincronizarVarias(collect([$empresa]), $origem);

        if ($resultado['atualizadas'] === 0) {
            return null;
        }

        return $empresa->fresh('financeiro')?->financeiro;
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return Collection<int, Collection<int, object>>
     */
    private static function cobrancas(ConnectionInterface $origem, Collection $companyIds): Collection
    {
        return $origem->table('charges')
            ->leftJoin('omie_categories', 'omie_categories.id', '=', 'charges.omie_category_id')
            ->whereNull('charges.deleted_at')
            ->where('charges.status', '!=', 'CANCELADO')
            ->whereIn('charges.company_id', $companyIds->all())
            ->orderBy('charges.competence_date')
            ->orderBy('charges.id')
            ->get([
                'charges.id',
                'charges.company_id',
                'charges.value',
                'charges.paid_value',
                'charges.competence_date',
                'charges.due_date',
                'charges.payment_date',
                'charges.status',
                'omie_categories.name as categoria',
            ])
            ->groupBy(fn (object $linha) => (int) $linha->company_id);
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return array<int, string>
     */
    private static function regimes(ConnectionInterface $origem, Collection $companyIds): array
    {
        $linhas = $origem->table('company_monthly_summaries as resumo')
            ->leftJoin('tax_regime_types as regime', 'regime.id', '=', 'resumo.tax_regime_type_id')
            ->whereIn('resumo.company_id', $companyIds->all())
            ->whereNotNull('resumo.tax_regime_type_id')
            ->orderByDesc('resumo.competence_date')
            ->orderByDesc('resumo.id')
            ->get(['resumo.company_id', 'regime.name']);

        $mapa = [];
        foreach ($linhas as $linha) {
            $id = (int) $linha->company_id;
            if (! isset($mapa[$id]) && filled($linha->name)) {
                $mapa[$id] = (string) $linha->name;
            }
        }

        return $mapa;
    }

    /**
     * @param  Collection<int, int>  $companyIds
     * @return array<int, Carbon>
     */
    private static function inicios(ConnectionInterface $origem, Collection $companyIds): array
    {
        $linhas = $origem->table('companies')
            ->whereIn('id', $companyIds->all())
            ->whereNotNull('contract_start_date')
            ->get(['id', 'contract_start_date']);

        $mapa = [];
        foreach ($linhas as $linha) {
            try {
                $mapa[(int) $linha->id] = Carbon::parse($linha->contract_start_date)->startOfDay();
            } catch (\Throwable) {
                // ignora data inválida
            }
        }

        return $mapa;
    }

    /**
     * @param  Collection<int, object>  $cobrancas
     */
    private static function gravar(
        Empresa $empresa,
        Collection $cobrancas,
        string $regime,
        ?Carbon $inicioContrato,
    ): EmpresaFinanceiro {
        $porMesHonorario = [];
        $porMesRecebido = [];
        $entradasPagas = 0.0;
        $entradasAbertas = 0.0;
        $datasRecebidas = [];
        $transacoes = [];

        foreach ($cobrancas as $cobranca) {
            $status = mb_strtoupper(trim((string) $cobranca->status));
            $valor = (float) $cobranca->value;
            $pago = (float) ($cobranca->paid_value ?: 0);
            $categoria = trim((string) ($cobranca->categoria ?? 'Honorários'));
            $ehHonorario = self::ehHonorario($categoria);
            $competencia = self::data($cobranca->competence_date);
            $pagamento = self::data($cobranca->payment_date);
            $chaveMes = $competencia?->format('Y-m') ?? 'sem-data';

            if ($ehHonorario && $valor > 0) {
                $porMesHonorario[$chaveMes] = ($porMesHonorario[$chaveMes] ?? 0) + $valor;
            }

            if (in_array($status, self::RECEBIDOS, true)) {
                $valorRecebido = $pago > 0 ? $pago : $valor;
                if ($valorRecebido <= 0) {
                    continue;
                }

                $entradasPagas += $valorRecebido;
                $porMesRecebido[$chaveMes] = ($porMesRecebido[$chaveMes] ?? 0) + $valorRecebido;
                if ($pagamento !== null) {
                    $datasRecebidas[] = $pagamento;
                } elseif ($competencia !== null) {
                    $datasRecebidas[] = $competencia;
                }

                $transacoes[] = [
                    'data' => ($pagamento ?? $competencia)?->format('d/m/Y') ?? '—',
                    'descricao' => $ehHonorario ? 'Honorário mensal' : $categoria,
                    'categoria' => $categoria !== '' ? $categoria : 'Honorários',
                    'tipo' => 'Entrada',
                    'valor' => (int) round($valorRecebido),
                    'status' => 'Recebido',
                    'ordem_data' => ($pagamento ?? $competencia)?->timestamp ?? 0,
                ];
            } elseif (in_array($status, self::ABERTOS, true) && $valor > 0) {
                $entradasAbertas += $valor;
                $transacoes[] = [
                    'data' => ($competencia ?? self::data($cobranca->due_date))?->format('d/m/Y') ?? '—',
                    'descricao' => $ehHonorario ? 'Honorário mensal' : $categoria,
                    'categoria' => $categoria !== '' ? $categoria : 'A receber',
                    'tipo' => 'Entrada',
                    'valor' => (int) round($valor),
                    'status' => 'Pendente',
                    'ordem_data' => ($competencia ?? self::data($cobranca->due_date))?->timestamp ?? 0,
                ];
            }
        }

        ksort($porMesHonorario);
        $valoresHonorario = array_values($porMesHonorario);
        $primeiro = $valoresHonorario[0] ?? 0.0;
        $atual = $valoresHonorario === [] ? 0.0 : $valoresHonorario[array_key_last($valoresHonorario)];

        $desde = $inicioContrato
            ?? ($datasRecebidas === [] ? today() : collect($datasRecebidas)->sort()->first());

        $financeiro = EmpresaFinanceiro::query()->updateOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'regime' => $regime,
                'desde' => $desde,
                'primeiro' => (int) round($primeiro),
                'atual' => (int) round($atual),
                'total' => (int) round($entradasPagas),
                'saldo' => (int) round($entradasAbertas),
                'receber' => (int) round($entradasAbertas),
                'despesas' => 0,
                'margem' => $entradasPagas > 0 ? 100 : 0,
            ],
        );

        $empresa->receitasMensais()->delete();
        ksort($porMesRecebido);
        $ordem = 0;
        foreach ($porMesRecebido as $chave => $valor) {
            $rotulo = $chave === 'sem-data'
                ? '—'
                : Carbon::createFromFormat('Y-m', $chave)->locale('pt_BR')->isoFormat('MMM/YY');

            $empresa->receitasMensais()->create([
                'mes' => $rotulo,
                'valor' => (int) round($valor),
                'ordem' => $ordem++,
            ]);
        }

        usort($transacoes, fn (array $a, array $b) => $b['ordem_data'] <=> $a['ordem_data']);
        $empresa->transacoes()->delete();
        foreach (array_slice($transacoes, 0, 50) as $indice => $item) {
            unset($item['ordem_data']);
            $empresa->transacoes()->create([...$item, 'ordem' => $indice]);
        }

        return $financeiro->refresh();
    }

    private static function ehHonorario(string $categoria): bool
    {
        $normalizada = mb_strtolower($categoria);

        return str_contains($normalizada, 'honorário')
            || str_contains($normalizada, 'honorario');
    }

    private static function data(mixed $valor): ?Carbon
    {
        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($valor))->startOfDay();
        }

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
