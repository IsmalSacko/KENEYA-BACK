<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ngoni Santé, lot 5 : la pharmacie.
 *
 * - `medicaments` gagne la DCI, la forme, le dosage, le conditionnement
 *   (unités par boîte) et la vente à l'unité. `stock` reste le total, compté
 *   en unités ; la synchro de l'app 3.x continue de le modifier.
 * - `lots` : numéro, péremption, quantité. On délivre d'abord ce qui périme
 *   le plus tôt.
 * - `fournisseurs` : les grossistes.
 * - `ordonnances` / `ordonnance_lignes` : prescrites, signées (QR vérifiable
 *   hors ligne), délivrées en une ou plusieurs fois, jamais au-delà.
 *
 * Reprise : le stock actuel de chaque médicament devient un lot « REPRISE »
 * avec sa date d'expiration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicaments', function (Blueprint $table) {
            $table->string('dci')->nullable()->after('nom');
            $table->string('forme', 40)->nullable()->after('dci');
            $table->string('dosage', 40)->nullable()->after('forme');
            $table->unsignedInteger('conditionnement')->default(1)->after('dosage');
            $table->boolean('vente_unite')->default(false)->after('conditionnement');
            $table->decimal('prix_unite', 10, 2)->nullable()->after('prix_unitaire');
            $table->boolean('ordonnance_requise')->default(false);
            $table->string('code_barres', 40)->nullable()->index();
        });

        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('telephone', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->string('numero', 40)->nullable();
            $table->date('peremption')->nullable();
            $table->unsignedInteger('quantite'); // en unités
            $table->unsignedInteger('quantite_recue');
            $table->decimal('prix_achat', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['medicament_id', 'peremption']);
        });

        Schema::create('ordonnances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescripteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->foreignId('visite_id')->nullable()->constrained('visites')->nullOnDelete();
            $table->string('patient_nom');
            $table->string('statut', 12)->default('emise'); // emise, partielle, delivree, annulee
            $table->text('signature'); // Ed25519, base64url
            $table->timestamps();
        });

        Schema::create('ordonnance_lignes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->cascadeOnDelete();
            $table->foreignId('medicament_id')->nullable()->constrained('medicaments')->nullOnDelete();
            $table->string('produit'); // ce qu'a écrit le prescripteur : « Paracétamol 500 mg »
            $table->string('posologie')->nullable();
            $table->unsignedInteger('quantite'); // en unités (comprimés, flacons…)
            $table->unsignedInteger('quantite_delivree')->default(0);
            $table->timestamps();
        });

        $this->reprendreStocks();
    }

    public function down(): void
    {
        Schema::dropIfExists('ordonnance_lignes');
        Schema::dropIfExists('ordonnances');
        Schema::dropIfExists('lots');
        Schema::dropIfExists('fournisseurs');
        Schema::table('medicaments', function (Blueprint $table) {
            $table->dropIndex(['code_barres']);
            $table->dropColumn(['dci', 'forme', 'dosage', 'conditionnement', 'vente_unite', 'prix_unite', 'ordonnance_requise', 'code_barres']);
        });
    }

    private function reprendreStocks(): void
    {
        $medicaments = DB::table('medicaments')->where('stock', '>', 0)->whereNull('deleted_at')
            ->get(['id', 'etablissement_id', 'stock', 'date_expiration']);
        foreach ($medicaments as $m) {
            DB::table('lots')->insert([
                'uuid' => (string) Str::uuid(),
                'etablissement_id' => $m->etablissement_id,
                'medicament_id' => $m->id,
                'numero' => 'REPRISE',
                'peremption' => $m->date_expiration,
                'quantite' => $m->stock,
                'quantite_recue' => $m->stock,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
