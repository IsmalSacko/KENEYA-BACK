<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ngoni Santé, lot 3 : agendas, créneaux, rappels, arrivée par QR.
 *
 * - `agendas` : celui d'un praticien ou d'un service, avec la durée des
 *   créneaux et le nombre de places par créneau (consultation de masse).
 * - `agenda_horaires` / `agenda_absences` : jours d'ouverture et congés.
 * - `rendez_vous` garde ses colonnes (app 3.x, synchro) et gagne le créneau
 *   réservé, la personne, les rappels et l'arrivée (le ticket créé).
 * - `personnes.carte_jeton` : ce que contient la carte santé QR ; on peut le
 *   renouveler si la carte est perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agendas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('membre_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nom');
            $table->unsignedSmallInteger('duree_creneau_min')->default(15);
            $table->unsignedTinyInteger('places_par_creneau')->default(1);
            $table->boolean('reservation_en_ligne')->default(true);
            $table->boolean('validation_auto')->default(true);
            $table->unsignedSmallInteger('delai_min_heures')->default(1);
            $table->unsignedSmallInteger('horizon_jours')->default(30);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('agenda_horaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agenda_id')->constrained('agendas')->cascadeOnDelete();
            $table->unsignedTinyInteger('jour'); // 1 = lundi … 7 = dimanche
            $table->string('debut', 5); // 08:00
            $table->string('fin', 5);   // 12:30
        });

        Schema::create('agenda_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agenda_id')->constrained('agendas')->cascadeOnDelete();
            $table->date('du');
            $table->date('au');
            $table->string('motif')->nullable();
            $table->timestamps();
        });

        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->string('statut', 15)->default('demande')->change();
            $table->foreignId('agenda_id')->nullable()->after('medecin_id')->constrained('agendas')->nullOnDelete();
            $table->foreignId('personne_id')->nullable()->after('agenda_id')->constrained('personnes')->nullOnDelete();
            $table->dateTime('debut')->nullable()->after('date_souhaitee');
            $table->dateTime('fin')->nullable()->after('debut');
            $table->string('canal', 10)->default('app')->after('statut');
            $table->timestamp('rappel_veille_at')->nullable();
            $table->timestamp('rappel_proche_at')->nullable();
            $table->timestamp('arrivee_at')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();

            $table->index(['agenda_id', 'debut']);
        });

        Schema::table('personnes', function (Blueprint $table) {
            $table->string('carte_jeton', 40)->nullable()->unique()->after('telephone');
        });
        foreach (DB::table('personnes')->whereNull('carte_jeton')->pluck('id') as $id) {
            DB::table('personnes')->where('id', $id)->update(['carte_jeton' => Str::random(32)]);
        }
    }

    public function down(): void
    {
        Schema::table('personnes', function (Blueprint $table) {
            $table->dropUnique(['carte_jeton']);
            $table->dropColumn('carte_jeton');
        });
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropIndex(['agenda_id', 'debut']);
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropConstrainedForeignId('personne_id');
            $table->dropConstrainedForeignId('agenda_id');
            $table->dropColumn(['debut', 'fin', 'canal', 'rappel_veille_at', 'rappel_proche_at', 'arrivee_at']);
        });
        Schema::dropIfExists('agenda_absences');
        Schema::dropIfExists('agenda_horaires');
        Schema::dropIfExists('agendas');
    }
};
