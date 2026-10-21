<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As contas Google cuja agenda vira reunião no HC Brain. O token de
 * atualização é o que permite ler a agenda sem a pessoa estar logada; ele é
 * gravado cifrado com a APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_google', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('nome');
            $table->text('refresh_token');
            $table->timestamp('sincronizada_em')->nullable();
            $table->string('erro')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_google');
    }
};
