<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 8 : les assurances.
 *
 * - `assureurs` : ceux avec qui l'établissement travaille (AMO, mutuelles…),
 *   leur taux de prise en charge et un plafond par visite.
 * - `visites` : l'assureur du patient, son numéro, le taux appliqué et la
 *   part de l'assureur, retirée de ce que le patient paie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assureurs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->unsignedTinyInteger('taux'); // % pris en charge
            $table->unsignedBigInteger('plafond')->nullable(); // par visite, unités mineures
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('visites', function (Blueprint $table) {
            $table->foreignId('assureur_id')->nullable()->constrained('assureurs')->nullOnDelete();
            $table->string('numero_assure', 40)->nullable();
            $table->unsignedTinyInteger('taux_couverture')->nullable();
            $table->unsignedBigInteger('part_assureur')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('visites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assureur_id');
            $table->dropColumn(['numero_assure', 'taux_couverture', 'part_assureur']);
        });
        Schema::dropIfExists('assureurs');
    }
};
