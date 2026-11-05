<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\AutomacaoHonorariosSync;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Preenche o financeiro (1º honorário, atual, total, movimentações) a partir
 * das cobranças do automacao-2-hc. Não depende do app_secret do Omie.
 */
class SincronizarHonorarios extends Command
{
    protected $signature = 'hc:sincronizar-honorarios
        {--empresa= : Id da empresa no Brain (opcional)}';

    protected $description = 'Traz os honorários do automacao-2-hc para o financeiro de cada empresa';

    public function handle(): int
    {
        $consulta = Empresa::query()
            ->whereNotNull('automacao_id')
            ->orderBy('nome');

        if ($this->option('empresa')) {
            $consulta->whereKey((string) $this->option('empresa'));
        }

        $empresas = $consulta->get();

        if ($empresas->isEmpty()) {
            $this->components->warn('Nenhuma empresa com automacao_id para sincronizar honorários.');

            return self::SUCCESS;
        }

        try {
            $resultado = AutomacaoHonorariosSync::sincronizarVarias($empresas, DB::connection('automacao'));
        } catch (QueryException $erro) {
            $this->components->error('Não foi possível ler o banco do automacao-2-hc: '.$erro->getMessage());

            return self::FAILURE;
        }

        $this->components->info(
            "Honorários: {$resultado['atualizadas']} empresa(s) atualizada(s), {$resultado['sem_cobranca']} sem cobrança no automacao."
        );

        return self::SUCCESS;
    }
}
