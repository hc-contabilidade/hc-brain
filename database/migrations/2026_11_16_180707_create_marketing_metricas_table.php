<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas mensais por plataforma. Hoje vêm do seeder; amanhã o
 * hc:sincronizar-marketing preenche a partir do YouTube/Instagram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_metricas', function (Blueprint $table) {
            $table->id();
            $table->string('periodo'); // YYYY-MM
            $table->string('plataforma'); // youtube | instagram
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comentarios')->default(0);
            $table->unsignedBigInteger('compartilhamentos')->default(0);
            $table->unsignedBigInteger('inscritos_ou_alcance')->default(0);
            $table->timestamps();

            $table->unique(['periodo', 'plataforma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_metricas');
    }
};
