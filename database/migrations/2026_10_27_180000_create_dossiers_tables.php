<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 6 : le dossier patient et la consultation.
 *
 * - `dossiers` : un par personne (il la suit d'un établissement à l'autre),
 *   ou, pour un patient sans compte, un par établissement.
 * - `examens_cliniques` : la consultation médicale (constantes, examen,
 *   diagnostic). La table `consultations` reste celle de la facturation 3.x.
 * - `acces_dossier` : l'accord du patient, donné par QR ou par code SMS, pour
 *   qu'un établissement voie l'historique tenu ailleurs.
 * - `lectures_dossier` : chaque ouverture d'un dossier, que le patient voit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('personne_id')->nullable()->unique()->constrained('personnes')->nullOnDelete();
            $table->foreignId('etablissement_id')->nullable()->constrained()->nullOnDelete(); // créateur, sans compte
            $table->string('nom');
            $table->string('telephone', 20)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('sexe', 1)->nullable();
            $table->string('groupe_sanguin', 3)->nullable();
            $table->text('allergies')->nullable();
            $table->text('antecedents')->nullable();
            $table->text('traitements')->nullable();
            $table->timestamps();
        });

        Schema::table('visites', function (Blueprint $table) {
            $table->foreignId('dossier_id')->nullable()->after('patient_id')->constrained('dossiers')->nullOnDelete();
        });

        Schema::create('examens_cliniques', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('visite_id')->nullable()->constrained('visites')->nullOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('praticien_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif');
            $table->json('constantes')->nullable();
            $table->text('examen')->nullable();
            $table->text('diagnostic')->nullable();
            $table->string('cim10', 10)->nullable();
            $table->text('conclusion')->nullable();
            $table->date('prochain_rdv')->nullable();
            $table->timestamps();

            $table->index(['dossier_id', 'created_at']);
        });

        Schema::create('acces_dossier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('par', 10); // qr, sms
            $table->timestamp('expire_at');
            $table->timestamp('revoque_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lectures_dossier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membre_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('historique')->default(false); // a vu ce qui vient d'ailleurs
            $table->timestamp('lu_at');
        });

        Schema::table('personnes', function (Blueprint $table) {
            $table->string('accord_jeton', 40)->nullable()->unique();
            $table->timestamp('accord_expire_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnes', function (Blueprint $table) {
            $table->dropUnique(['accord_jeton']);
            $table->dropColumn(['accord_jeton', 'accord_expire_at']);
        });
        Schema::dropIfExists('lectures_dossier');
        Schema::dropIfExists('acces_dossier');
        Schema::dropIfExists('examens_cliniques');
        Schema::table('visites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_id');
        });
        Schema::dropIfExists('dossiers');
    }
};
