<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 7 : le laboratoire.
 *
 * - `analyses` : le catalogue de l'établissement, avec ses valeurs normales
 *   et l'acte du tarif qui la facture.
 * - `demandes_examen` : la demande du médecin (ou du labo, pour un patient
 *   qui vient seul), son prélèvement et sa validation.
 * - `demande_analyses` : chaque analyse demandée et son résultat. Le nom,
 *   l'unité et les normes y sont recopiés : un résultat ne change pas quand
 *   le catalogue change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->string('categorie', 30);
            $table->string('type_resultat', 10); // nombre, choix, texte
            $table->string('unite', 20)->nullable();
            $table->decimal('normale_min', 10, 2)->nullable();
            $table->decimal('normale_max', 10, 2)->nullable();
            $table->json('choix')->nullable();
            $table->string('choix_normal', 40)->nullable();
            $table->foreignId('tarif_id')->nullable()->constrained('tarifs')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['etablissement_id', 'code']);
        });

        Schema::create('demandes_examen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('visite_id')->nullable()->constrained('visites')->nullOnDelete();
            $table->foreignId('prescripteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statut', 12)->default('demandee'); // demandee, prelevee, validee, annulee
            $table->boolean('urgent')->default(false);
            $table->text('renseignements')->nullable();
            $table->timestamp('preleve_at')->nullable();
            $table->foreignId('preleve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['etablissement_id', 'statut']);
        });

        Schema::create('demande_analyses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('demande_id')->constrained('demandes_examen')->cascadeOnDelete();
            $table->foreignId('analyse_id')->nullable()->constrained('analyses')->nullOnDelete();
            $table->string('nom');
            $table->string('type_resultat', 10);
            $table->string('unite', 20)->nullable();
            $table->string('normale', 60)->nullable();
            $table->string('resultat', 2000)->nullable();
            $table->decimal('valeur', 10, 2)->nullable();
            $table->boolean('anormal')->nullable();
            $table->string('commentaire', 500)->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saisi_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demande_analyses');
        Schema::dropIfExists('demandes_examen');
        Schema::dropIfExists('analyses');
    }
};
