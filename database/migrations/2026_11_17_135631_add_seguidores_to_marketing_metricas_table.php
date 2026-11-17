<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audiência acumulada: inscritos totais no YouTube ou seguidores no Instagram.
 * `inscritos_ou_alcance` continua como ganho do mês (YT) ou alcance (IG).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_metricas', function (Blueprint $table) {
            $table->unsignedBigInteger('seguidores')->default(0)->after('inscritos_ou_alcance');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_metricas', function (Blueprint $table) {
            $table->dropColumn('seguidores');
        });
    }
};
