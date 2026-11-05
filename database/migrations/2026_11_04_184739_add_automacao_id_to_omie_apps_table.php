<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga cada app Omie do Brain ao registro correspondente em omie_bases
 * do automacao-2-hc, para o sync em massa reconhecer a mesma base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omie_apps', function (Blueprint $table) {
            $table->unsignedBigInteger('automacao_id')
                ->nullable()
                ->unique()
                ->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('omie_apps', function (Blueprint $table) {
            $table->dropUnique(['automacao_id']);
            $table->dropColumn('automacao_id');
        });
    }
};
