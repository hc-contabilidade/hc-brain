<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vídeos/reels publicados com métricas por peça — permite apontar o
 * conteúdo que mais puxou inscritos/seguidores no mês.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_conteudos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('plataforma'); // youtube | instagram
            $table->string('periodo'); // YYYY-MM
            $table->date('publicado_em')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comentarios')->default(0);
            $table->unsignedInteger('ganho_inscritos')->default(0);
            $table->unsignedInteger('ganho_seguidores')->default(0);
            $table->boolean('destaque')->default(false);
            $table->string('url')->nullable();
            $table->timestamps();

            $table->index(['plataforma', 'periodo']);
            $table->index(['plataforma', 'destaque']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_conteudos');
    }
};
