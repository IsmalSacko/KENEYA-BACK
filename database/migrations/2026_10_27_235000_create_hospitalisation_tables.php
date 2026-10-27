<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 8 : l'hospitalisation.
 *
 * - `chambres` et `lits` : la capacité, avec le prix de la nuit (acte du tarif).
 * - `hospitalisations` : admission sur un lit, transferts, sortie (facturée
 *   à la nuit sur la visite).
 * - `soins_prescrits` : le plan de soins (produit, dose, voie, horaires).
 * - `administrations` : chaque prise faite au lit, après scan du bracelet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chambres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('service', 40)->nullable();
            $table->foreignId('tarif_id')->nullable()->constrained('tarifs')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('chambre_id')->constrained('chambres')->cascadeOnDelete();
            $table->string('numero', 10);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['chambre_id', 'numero']);
        });

        Schema::create('hospitalisations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('visite_id')->nullable()->constrained('visites')->nullOnDelete();
            $table->foreignId('lit_id')->nullable()->constrained('lits')->nullOnDelete();
            $table->foreignId('medecin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif');
            $table->string('statut', 10)->default('en_cours'); // en_cours, sortie
            $table->timestamp('entree_at');
            $table->timestamp('sortie_at')->nullable();
            $table->string('mode_sortie', 12)->nullable();
            $table->text('resume_sortie')->nullable();
            $table->timestamps();

            $table->index(['etablissement_id', 'statut']);
        });

        Schema::create('soins_prescrits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hospitalisation_id')->constrained('hospitalisations')->cascadeOnDelete();
            $table->foreignId('prescripteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('produit');
            $table->string('dose', 60)->nullable();
            $table->string('voie', 10); // po, iv, im, sc, autre
            $table->json('horaires'); // ["08:00", "20:00"]
            $table->date('debut');
            $table->date('fin')->nullable();
            $table->string('instructions', 500)->nullable();
            $table->timestamp('arrete_at')->nullable();
            $table->timestamps();
        });

        Schema::create('administrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('soin_id')->constrained('soins_prescrits')->cascadeOnDelete();
            $table->foreignId('hospitalisation_id')->constrained('hospitalisations')->cascadeOnDelete();
            $table->dateTime('prevue_at');
            $table->string('statut', 10); // faite, refusee, omise
            $table->timestamp('faite_at');
            $table->foreignId('par')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('bracelet_verifie')->default(false);
            $table->string('commentaire', 500)->nullable();
            $table->timestamps();

            $table->unique(['soin_id', 'prevue_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrations');
        Schema::dropIfExists('soins_prescrits');
        Schema::dropIfExists('hospitalisations');
        Schema::dropIfExists('lits');
        Schema::dropIfExists('chambres');
    }
};
