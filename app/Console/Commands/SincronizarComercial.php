<?php

namespace App\Console\Commands;

use App\Support\AutomacaoComercialSync;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Preenche indicadores_comerciais a partir do CRM do automacao-2-hc
 * (Painel de Vendas).
 */
class SincronizarComercial extends Command
{
    protected $signature = 'hc:sincronizar-comercial';

    protected $description = 'Traz os indicadores do comercial (leads, funil, vendas) do automacao-2-hc';

    public function handle(): int
    {
        try {
            $resultado = AutomacaoComercialSync::sincronizar(DB::connection('automacao'));
        } catch (QueryException $erro) {
            $this->components->error('Não foi possível ler o banco do automacao-2-hc: '.$erro->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Comercial: {$resultado['periodos']} período(s) atualizado(s).");

        return self::SUCCESS;
    }
}
