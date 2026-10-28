<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Abonnements, comme Ngoni Caisse : des formules (essai, Essentiel, Pro,
 * Hôpital) avec leurs tarifs au mois, au trimestre, au semestre et à l'année,
 * réglables depuis la console ; un abonnement par établissement ; les
 * demandes de paiement (Orange Money, Wave…) que la plateforme valide, et
 * qui font l'historique et les reçus.
 *
 * Les établissements de démonstration ont Hôpital sans échéance ; les autres
 * commencent un essai de 14 jours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnes', function (Blueprint $table) {
            $table->boolean('admin_plateforme')->default(false);
        });

        Schema::create('formules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 60);
            $table->string('description', 255)->nullable();
            $table->json('modules');
            $table->json('fonctions');
            $table->unsignedSmallInteger('jours_essai')->default(0);
            $table->unsignedSmallInteger('max_membres')->nullable();
            $table->boolean('actif')->default(true);
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('formule_tarifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formule_id')->constrained('formules')->cascadeOnDelete();
            $table->unsignedTinyInteger('mois');
            $table->unsignedInteger('montant');
            $table->string('devise', 3)->default('XOF');
            $table->timestamps();
            $table->unique(['formule_id', 'mois', 'devise']);
        });

        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->unique()->constrained('etablissements')->cascadeOnDelete();
            $table->string('formule', 20);
            $table->date('debut');
            // Null : sans échéance (accordé à la main).
            $table->date('fin')->nullable();
            $table->boolean('manuel')->default(false);
            $table->foreignId('accorde_par')->nullable()->constrained('personnes')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('demandes_abonnement', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('demandeur_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->string('formule', 20);
            $table->unsignedTinyInteger('mois');
            $table->unsignedInteger('montant');
            $table->string('devise', 3);
            $table->string('moyen', 20);
            $table->string('telephone', 30)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('preuve_chemin')->nullable();
            $table->string('statut', 12)->default('en_attente'); // en_attente, approuvee, refusee, annulee
            $table->timestamp('decide_at')->nullable();
            $table->foreignId('decide_par')->nullable()->constrained('personnes')->nullOnDelete();
            $table->string('motif_refus', 255)->nullable();
            $table->string('recu_numero', 20)->nullable()->unique();
            $table->date('periode_debut')->nullable();
            $table->date('periode_fin')->nullable();
            $table->timestamps();
            $table->index(['statut', 'created_at']);
        });

        $tous = ['file', 'rdv', 'consultation', 'caisse', 'pharmacie', 'labo', 'maternite', 'hospitalisation', 'badges', 'assurances'];
        $formules = [
            ['essai', 'Essai gratuit', 'Tout Ngoni Santé pendant 14 jours.', $tous, ['ecrans', 'rapport'], 14, null, null],
            ['essentiel', 'Essentiel', 'Cabinet médical ou dentaire, dépôt de médicaments.',
                ['file', 'rdv', 'consultation', 'caisse', 'pharmacie'], [], 0, 5, [1 => 5000, 3 => 14000, 6 => 27000, 12 => 50000]],
            ['pro', 'Pro', 'Centre de santé, maternité, laboratoire, pharmacie.',
                ['file', 'rdv', 'consultation', 'caisse', 'pharmacie', 'labo', 'maternite'], ['ecrans', 'rapport'], 0, 15,
                [1 => 15000, 3 => 42000, 6 => 80000, 12 => 150000]],
            ['hopital', 'Hôpital', 'Clinique, hôpital : tout, sans limite d’équipe.', $tous, ['ecrans', 'rapport'], 0, null,
                [1 => 40000, 3 => 110000, 6 => 210000, 12 => 400000]],
        ];
        foreach ($formules as $ordre => [$code, $nom, $description, $modules, $fonctions, $essai, $max, $tarifs]) {
            $id = DB::table('formules')->insertGetId([
                'code' => $code, 'nom' => $nom, 'description' => $description,
                'modules' => json_encode($modules), 'fonctions' => json_encode($fonctions),
                'jours_essai' => $essai, 'max_membres' => $max, 'ordre' => $ordre,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($tarifs ?? [] as $mois => $montant) {
                DB::table('formule_tarifs')->insert([
                    'formule_id' => $id, 'mois' => $mois, 'montant' => $montant, 'devise' => 'XOF',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('etablissements')->get(['id', 'uuid']) as $e) {
            $demo = Str::startsWith((string) $e->uuid, '6e0d1a10-');
            DB::table('abonnements')->insert([
                'etablissement_id' => $e->id,
                'formule' => $demo ? 'hopital' : 'essai',
                'debut' => now()->toDateString(),
                'fin' => $demo ? null : now()->addDays(14)->toDateString(),
                'manuel' => $demo,
                'note' => $demo ? 'Démonstration' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_abonnement');
        Schema::dropIfExists('abonnements');
        Schema::dropIfExists('formule_tarifs');
        Schema::dropIfExists('formules');
        Schema::table('personnes', fn (Blueprint $table) => $table->dropColumn('admin_plateforme'));
    }
};
