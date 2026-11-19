<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue vídeo longo, Short e post (Instagram) no ranking do Marketing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conteudos', function (Blueprint $table) {
            $table->string('formato', 16)->default('video')->after('plataforma');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conteudos', function (Blueprint $table) {
            $table->dropColumn('formato');
        });
    }
};
