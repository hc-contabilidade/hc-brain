<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ideias de vídeo do Marketing: rascunho usável pela equipe até a
 * integração com redes sociais amadurecer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_ideias', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->string('plataforma'); // youtube | instagram | ambos
            $table->string('status'); // rascunho | aprovada | publicada | arquivada
            $table->string('responsavel')->nullable();
            $table->string('prioridade')->default('media');
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_ideias');
    }
};
