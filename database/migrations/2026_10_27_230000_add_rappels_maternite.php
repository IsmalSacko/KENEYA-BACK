<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rappels SMS de la maternité : un seul par CPN, un par jour de vaccins. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpn', function (Blueprint $table) {
            $table->timestamp('rappel_at')->nullable();
        });
        Schema::table('dossiers', function (Blueprint $table) {
            $table->date('rappel_vaccins_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn('rappel_vaccins_le');
        });
        Schema::table('cpn', function (Blueprint $table) {
            $table->dropColumn('rappel_at');
        });
    }
};
