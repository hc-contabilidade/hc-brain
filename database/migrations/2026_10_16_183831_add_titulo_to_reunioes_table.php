<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nome do evento no Google Agenda. Sem empresa ligada, a agenda mostra este
 * título no lugar de "Sem empresa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->string('titulo')->nullable()->after('empresa_id');
        });

        // Reuniões já importadas: a primeira linha do resumo era o summary.
        DB::table('reunioes')
            ->whereNotNull('google_evento_id')
            ->orderBy('id')
            ->each(function (object $reuniao): void {
                $primeira = trim(strtok((string) $reuniao->resumo, "\n") ?: '');

                if ($primeira === '') {
                    return;
                }

                DB::table('reunioes')->where('id', $reuniao->id)->update([
                    'titulo' => mb_substr($primeira, 0, 255),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->dropColumn('titulo');
        });
    }
};
