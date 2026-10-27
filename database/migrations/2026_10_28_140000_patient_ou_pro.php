<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient ou professionnel : la personne le dit à l'inscription (un patient
 * ne voit pas la partie pro). Un établissement créé depuis l'app attend sa
 * validation par Ngoni Santé avant d'être visible des patients ; ceux qui
 * existent déjà sont validés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnes', function (Blueprint $table) {
            $table->boolean('pro')->default(false);
        });
        Schema::table('etablissements', function (Blueprint $table) {
            $table->timestamp('valide_at')->nullable();
            $table->string('autorisation', 120)->nullable();
            $table->string('responsable', 120)->nullable();
        });
        DB::table('etablissements')->update(['valide_at' => now()]);
        // Ceux qui travaillent déjà dans un établissement sont des pros.
        DB::table('personnes')->whereIn('id', DB::table('users')->whereNotNull('personne_id')->select('personne_id'))->update(['pro' => true]);
    }

    public function down(): void
    {
        Schema::table('etablissements', fn (Blueprint $table) => $table->dropColumn(['valide_at', 'autorisation', 'responsable']));
        Schema::table('personnes', fn (Blueprint $table) => $table->dropColumn('pro'));
    }
};
