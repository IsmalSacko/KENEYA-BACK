<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les établissements de l'éditeur (démonstrations, essais) : hors des
 * chiffres d'abonnements de la console. Les démonstrations le sont d'office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->boolean('interne')->default(false);
        });
        DB::table('etablissements')->where('uuid', 'like', '6e0d1a10-%')->update(['interne' => true]);
    }

    public function down(): void
    {
        Schema::table('etablissements', fn (Blueprint $table) => $table->dropColumn('interne'));
    }
};
