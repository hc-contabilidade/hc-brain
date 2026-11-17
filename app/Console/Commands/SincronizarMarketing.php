<?php

namespace App\Console\Commands;

use App\Support\MarketingMetricasSync;
use Illuminate\Console\Command;

/**
 * Puxa métricas oficiais: YouTube (API key) e Instagram (quando houver token Meta).
 */
class SincronizarMarketing extends Command
{
    protected $signature = 'hc:sincronizar-marketing';

    protected $description = 'Sincroniza métricas de Marketing (YouTube Data API / Instagram Graph)';

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
