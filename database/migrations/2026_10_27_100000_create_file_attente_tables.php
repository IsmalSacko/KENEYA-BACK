<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ngoni Santé, lot 2 : la file d'attente.
 *
 * - `services` : Consultation, Caisse, Pharmacie… chacun a sa série de
 *   tickets (préfixe A, C, P…), remise à zéro chaque jour.
 * - `points_service` : Salle 2, Guichet 1… là où l'on appelle.
 * - `tickets` : un passage dans un service. Un transfert crée un nouveau
 *   ticket lié au précédent (même visite).
 * - `appareils` : écrans TV, bornes et guichets appairés par QR, sans compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('prefixe', 3);
            $table->unsignedSmallInteger('duree_moyenne_min')->default(10);
            // Après ce nombre d'appels prioritaires de suite, un ordinaire passe.
            $table->unsignedTinyInteger('ratio_prioritaires')->default(2);
            $table->boolean('ticket_distance')->default(true);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('points_service', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('nom');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->date('jour');
            $table->unsignedInteger('numero');
            $table->string('code', 10); // A012
            $table->string('priorite', 15)->default('ordinaire');
            $table->string('statut', 15)->default('emis');
            $table->string('canal', 10)->default('guichet'); // borne, guichet, app, sms
            $table->foreignId('personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('nom')->nullable();
            $table->string('telephone', 20)->nullable();
            $table->foreignId('point_service_id')->nullable()->constrained('points_service')->nullOnDelete();
            $table->foreignId('appele_par')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('appels')->default(0);
            $table->foreignId('ticket_precedent_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->boolean('prevenu_bientot')->default(false);
            $table->timestamp('emis_at');
            $table->timestamp('appele_at')->nullable();
            $table->timestamp('servi_at')->nullable();
            $table->timestamp('termine_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'jour', 'numero']);
            $table->index(['etablissement_id', 'jour', 'statut']);
        });

        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 10); // ecran, borne
            $table->string('nom');
            $table->string('jeton', 64)->nullable()->unique(); // sha256 du jeton
            $table->string('code_appairage', 6)->nullable()->unique();
            $table->timestamp('code_expire_at')->nullable();
            $table->json('services')->nullable(); // uuid des services affichés ; null = tous
            $table->timestamp('appaire_at')->nullable();
            $table->timestamp('dernier_contact_at')->nullable();
            $table->timestamp('revoque_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('points_service');
        Schema::dropIfExists('services');
    }
};
