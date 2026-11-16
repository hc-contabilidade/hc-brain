<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos extras do Painel de Vendas do Automacao (abertura/transf., fonte, funil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicadores_comerciais', function (Blueprint $table) {
            $table->unsignedInteger('abertura')->default(0)->after('closed');
            $table->unsignedInteger('transferencia')->default(0)->after('abertura');
            $table->unsignedInteger('rebotes')->default(0)->after('transferencia');
            $table->unsignedInteger('meetings_done')->default(0)->after('meetings');
            $table->json('sources')->nullable()->after('origins');
            $table->json('funnel')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('indicadores_comerciais', function (Blueprint $table) {
            $table->dropColumn([
                'abertura',
                'transferencia',
                'rebotes',
                'meetings_done',
                'sources',
                'funnel',
            ]);
        });
    }
};
