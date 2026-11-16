<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove o financeiro semeado das empresas fictícias (Empresa X, Y…).
 * A carteira do Financeiro geral passa a refletir só dados reais.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const EMPRESAS_DEMONSTRATIVAS = [
        'empresaX',
        'empresaY',
        'empresaZ',
        'empresaW',
        'empresaK',
    ];

    public function up(): void
    {
        DB::table('transacoes')->whereIn('empresa_id', self::EMPRESAS_DEMONSTRATIVAS)->delete();
        DB::table('receitas_mensais')->whereIn('empresa_id', self::EMPRESAS_DEMONSTRATIVAS)->delete();
        DB::table('empresa_financeiros')->whereIn('empresa_id', self::EMPRESAS_DEMONSTRATIVAS)->delete();
    }

    public function down(): void
    {
        // Dados demonstrativos não são restaurados.
    }
};
