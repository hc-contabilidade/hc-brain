<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cor que o evento tem no Google Agenda, em hexadecimal. Reunião registrada
 * à mão não tem, e a agenda pinta pelo status como sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->string('cor', 7)->nullable()->after('link_meet');
        });
    }

    public function down(): void
    {
        Schema::table('reunioes', function (Blueprint $table) {
            $table->dropColumn('cor');
        });
    }
};
