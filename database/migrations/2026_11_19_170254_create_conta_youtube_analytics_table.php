<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conta Google autorizada a ler YouTube Analytics do canal HC (Insights).
 * Em geral há no máximo uma linha ativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_youtube_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('nome')->nullable();
            $table->string('channel_id')->nullable();
            $table->text('refresh_token');
            $table->string('erro')->nullable();
            $table->timestamp('sincronizada_em')->nullable();
            $table->timestamps();

            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_youtube_analytics');
    }
};
