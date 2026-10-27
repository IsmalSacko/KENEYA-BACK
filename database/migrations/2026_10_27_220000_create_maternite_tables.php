<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 7 : la maternité.
 *
 * - `grossesses` : DDR, terme prévu, gestité et parité, facteurs de risque.
 * - `cpn` : chaque consultation prénatale (poids, TA, hauteur utérine, BCF,
 *   doses de TPI et de VAT, fer, moustiquaire).
 * - `accouchements` et `naissances` : chaque enfant reçoit son dossier,
 *   rattaché à celui de sa mère (`dossiers.responsable_id`) ; la mère le voit
 *   dans son app.
 * - `vaccinations` : le carnet de vaccination, qui ne se perd plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->foreignId('responsable_id')->nullable()->after('etablissement_id')->constrained('dossiers')->nullOnDelete();
        });

        Schema::create('grossesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->date('ddr');
            $table->date('dpa');
            $table->unsignedTinyInteger('gestite')->nullable();
            $table->unsignedTinyInteger('parite')->nullable();
            $table->text('risques')->nullable();
            $table->string('statut', 12)->default('en_cours'); // en_cours, accouchee, interrompue
            $table->timestamp('terminee_at')->nullable();
            $table->timestamps();

            $table->index(['etablissement_id', 'statut']);
        });

        Schema::create('cpn', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('grossesse_id')->constrained('grossesses')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('praticien_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('numero');
            $table->unsignedTinyInteger('semaines');
            $table->decimal('poids', 5, 1)->nullable();
            $table->unsignedSmallInteger('ta_sys')->nullable();
            $table->unsignedSmallInteger('ta_dia')->nullable();
            $table->unsignedTinyInteger('hauteur_uterine')->nullable();
            $table->unsignedSmallInteger('bcf')->nullable();
            $table->string('presentation', 20)->nullable();
            $table->boolean('oedemes')->nullable();
            $table->string('albuminurie', 10)->nullable();
            $table->unsignedTinyInteger('tpi')->nullable(); // dose de SP
            $table->unsignedTinyInteger('vat')->nullable(); // dose
            $table->boolean('fer')->default(false);
            $table->boolean('moustiquaire')->default(false);
            $table->text('observations')->nullable();
            $table->date('prochain_rdv')->nullable();
            $table->timestamps();
        });

        Schema::create('accouchements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('grossesse_id')->unique()->constrained('grossesses')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('praticien_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('date_heure');
            $table->string('mode', 12); // voie_basse, ventouse, cesarienne
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('naissances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('accouchement_id')->constrained('accouchements')->cascadeOnDelete();
            $table->foreignId('dossier_id')->nullable()->constrained('dossiers')->nullOnDelete();
            $table->string('sexe', 1);
            $table->boolean('vivant')->default(true);
            $table->unsignedSmallInteger('poids_g')->nullable();
            $table->unsignedTinyInteger('taille_cm')->nullable();
            $table->unsignedTinyInteger('apgar1')->nullable();
            $table->unsignedTinyInteger('apgar5')->nullable();
            $table->timestamps();
        });

        Schema::create('vaccinations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fait_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vaccin', 20);
            $table->date('fait_le');
            $table->string('lot', 40)->nullable();
            $table->timestamps();

            $table->unique(['dossier_id', 'vaccin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccinations');
        Schema::dropIfExists('naissances');
        Schema::dropIfExists('accouchements');
        Schema::dropIfExists('cpn');
        Schema::dropIfExists('grossesses');
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_id');
        });
    }
};
