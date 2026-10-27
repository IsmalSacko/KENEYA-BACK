<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fiche patient de l'accueil et le tri.
 *
 * - `dossiers.adresse` : le quartier, pour retrouver et rappeler le patient.
 * - `dossiers.naissance_estimee` : on connaît souvent l'âge, pas la date.
 * - `visites.constantes` : prises au tri par l'infirmier, avant le médecin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->string('adresse')->nullable()->after('telephone');
            $table->boolean('naissance_estimee')->default(false)->after('date_naissance');
        });
        Schema::table('visites', function (Blueprint $table) {
            $table->json('constantes')->nullable();
            $table->timestamp('constantes_at')->nullable();
            $table->foreignId('constantes_par')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('constantes_par');
            $table->dropColumn(['constantes', 'constantes_at']);
        });
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn(['adresse', 'naissance_estimee']);
        });
    }
};
