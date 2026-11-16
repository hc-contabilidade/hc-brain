<?php

namespace App\Console\Commands;

use App\Support\MarketingMetricasSync;
use Illuminate\Console\Command;

/**
 * Stub: amanhã puxa YouTube/Instagram. Hoje só reporta o estado do encaixe.
 */
class SincronizarMarketing extends Command
{
    protected $signature = 'hc:sincronizar-marketing';

    protected $description = 'Sincroniza métricas de Marketing (YouTube/Instagram) — stub até as APIs';

    public function handle(): int
    {
        $resultado = MarketingMetricasSync::sincronizar();

        if ($resultado['aviso'] !== null) {
            $this->components->warn($resultado['aviso']);
        }

        $this->components->info("Marketing: {$resultado['periodos']} período(s) atualizado(s).");

        return self::SUCCESS;
    }
}
