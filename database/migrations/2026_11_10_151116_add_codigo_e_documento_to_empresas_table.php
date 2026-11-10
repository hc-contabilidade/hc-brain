<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código e documento (CNPJ/CPF) como estão na automação, para a lista do
 * financeiro. Empresas cadastradas só no HC Brain ficam com os dois nulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('codigo')->nullable()->after('automacao_id');
            $table->string('documento')->nullable()->after('codigo');
            $table->string('documento_tipo', 4)->nullable()->after('documento');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['codigo', 'documento', 'documento_tipo']);
        });
    }
};
