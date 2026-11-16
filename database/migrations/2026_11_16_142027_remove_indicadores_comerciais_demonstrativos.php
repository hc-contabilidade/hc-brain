<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove os períodos fictícios do ComercialSeeder antigo.
 * Os reais voltam no hc:sincronizar-comercial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('indicadores_comerciais')
            ->whereIn('periodo', ['2026-07', '2026-08', '2026-09'])
            ->whereIn('revenue', [151200, 176400, 202800])
            ->delete();
    }

    public function down(): void
    {
        // Dados demonstrativos não são restaurados.
    }
};
