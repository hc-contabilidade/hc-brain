<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reunião que vem do Google Agenda guarda de qual conta e de qual evento
 * veio, para ser atualizada em vez de duplicada. A empresa passa a ser
 * opcional: quando o evento não diz de quem é, a reunião entra "Sem empresa"
 * e alguém a liga depois pela tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->string('empresa_id')->nullable()->change();
            $table->foreignId('conta_google_id')->nullable()->after('empresa_id')->constrained('contas_google')->nullOnDelete();
            $table->string('google_evento_id')->nullable()->after('conta_google_id');
            $table->string('link_meet')->nullable()->after('google_evento_id');

            $table->unique(['conta_google_id', 'google_evento_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->dropUnique(['conta_google_id', 'google_evento_id']);
            $table->dropConstrainedForeignId('conta_google_id');
            $table->dropColumn(['google_evento_id', 'link_meet']);
        });
    }
};
