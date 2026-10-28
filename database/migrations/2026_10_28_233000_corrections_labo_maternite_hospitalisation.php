<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corriger sans effacer :
 *
 * - `administrations.corrections` : une prise notée par erreur (donnée,
 *   refusée, non faite) se corrige, l'ancienne saisie reste écrite.
 * - `demande_analyses.corrections` : un résultat validé se corrige avec la
 *   raison, l'ancien résultat reste écrit.
 * - `demandes_examen.motif_annulation`, `rejet_motif`, `rejete_at` : pourquoi
 *   une demande est annulée, ou son échantillon rejeté (à reprélever).
 * - `grossesses.issue` : une grossesse finie autrement que par
 *   l'accouchement (fausse couche, IVG, GEU, transférée, perdue de vue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('administrations', function (Blueprint $table) {
            $table->json('corrections')->nullable()->after('commentaire');
        });
        Schema::table('demande_analyses', function (Blueprint $table) {
            $table->json('corrections')->nullable()->after('commentaire');
        });
        Schema::table('demandes_examen', function (Blueprint $table) {
            $table->string('motif_annulation', 255)->nullable()->after('renseignements');
            $table->string('rejet_motif', 255)->nullable()->after('motif_annulation');
            $table->timestamp('rejete_at')->nullable()->after('rejet_motif');
        });
        Schema::table('grossesses', function (Blueprint $table) {
            $table->string('issue', 20)->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('grossesses', function (Blueprint $table) {
            $table->dropColumn('issue');
        });
        Schema::table('demandes_examen', function (Blueprint $table) {
            $table->dropColumn(['motif_annulation', 'rejet_motif', 'rejete_at']);
        });
        Schema::table('demande_analyses', function (Blueprint $table) {
            $table->dropColumn('corrections');
        });
        Schema::table('administrations', function (Blueprint $table) {
            $table->dropColumn('corrections');
        });
    }
};
