<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chats da Sofia por pessoa da equipe. O navegador continua com o recorte
 * da conversa aberta; o histórico em si passa a viver na base, então não
 * some ao trocar de computador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sofia_conversas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('titulo');
            $table->string('modo', 16);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('sofia_mensagens', function (Blueprint $table) {
            $table->id();
            $table->uuid('conversa_id');
            $table->string('autor', 16);
            $table->text('texto');
            $table->boolean('voz')->default(false);
            $table->timestamps();

            $table->foreign('conversa_id')->references('id')->on('sofia_conversas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sofia_mensagens');
        Schema::dropIfExists('sofia_conversas');
    }
};
