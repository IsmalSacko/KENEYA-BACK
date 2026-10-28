<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Combien d'établissements une personne peut ouvrir selon sa formule (chacun
 * paie son abonnement). Vide : sans limite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formules', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_etablissements')->nullable()->after('max_membres');
        });
        foreach (['essai' => 2, 'essentiel' => 2, 'pro' => 3, 'hopital' => 10] as $code => $max) {
            DB::table('formules')->where('code', $code)->update(['max_etablissements' => $max]);
        }
    }

    public function down(): void
    {
        Schema::table('formules', fn (Blueprint $table) => $table->dropColumn('max_etablissements'));
    }
};
