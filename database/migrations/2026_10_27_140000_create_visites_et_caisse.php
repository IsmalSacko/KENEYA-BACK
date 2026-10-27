<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 4 : la visite et la caisse unifiée.
 *
 * - `visites` : le passage du patient ce jour-là. Elle s'ouvre avec son
 *   premier ticket et suit ses transferts (consultation → caisse → labo…).
 * - `tarifs` : les actes de l'établissement et leur prix.
 * - `visite_lignes` : la facture unique de la visite.
 * - `sessions_caisse` / `encaissements` : qui encaisse quoi, par quel moyen
 *   (espèces, Orange Money…), et la clôture de caisse avec l'écart.
 *
 * Montants en entiers, dans l'unité de la devise de l'établissement (un
 * franc CFA n'a pas de centimes ; un cedi en a : on stocke des pesewas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('nom')->nullable();
            $table->string('telephone', 20)->nullable();
            $table->string('devise', 3);
            $table->string('statut', 12)->default('ouverte'); // ouverte, cloturee
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paye')->default(0);
            $table->timestamp('ouverte_at');
            $table->timestamp('cloturee_at')->nullable();
            $table->timestamps();

            $table->index(['etablissement_id', 'statut']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('visite_id')->nullable()->after('patient_id')->constrained('visites')->nullOnDelete();
        });

        Schema::create('tarifs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('categorie', 20)->default('acte'); // consultation, labo, soin, acte
            $table->unsignedBigInteger('prix');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions_caisse', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membre_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('fond_initial')->default(0);
            $table->timestamp('ouverte_at');
            $table->timestamp('cloturee_at')->nullable();
            $table->unsignedBigInteger('especes_comptees')->nullable();
            $table->bigInteger('ecart')->nullable();
            $table->timestamps();
        });

        Schema::create('visite_lignes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('visite_id')->constrained('visites')->cascadeOnDelete();
            $table->foreignId('tarif_id')->nullable()->constrained('tarifs')->nullOnDelete();
            $table->string('libelle');
            $table->unsignedInteger('quantite')->default(1);
            $table->unsignedBigInteger('prix_unitaire');
            $table->unsignedBigInteger('montant');
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('encaissements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('visite_id')->constrained('visites')->cascadeOnDelete();
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->nullOnDelete();
            $table->unsignedBigInteger('montant');
            $table->string('moyen', 20); // especes, orange_money, wave, moov_money, mtn_momo…
            $table->string('reference', 60)->nullable(); // n° de transaction Mobile Money
            $table->foreignId('encaisse_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encaissements');
        Schema::dropIfExists('visite_lignes');
        Schema::dropIfExists('sessions_caisse');
        Schema::dropIfExists('tarifs');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visite_id');
        });
        Schema::dropIfExists('visites');
    }
};
