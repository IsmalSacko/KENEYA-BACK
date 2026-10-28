<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tout l'abonnement dans la Console Ngoni (contrat v1.1) :
 * - qui a décidé quand c'est la console (« Console · Ismaila SACKO ») ;
 * - la référence du paiement (n° de transaction) ;
 * - les paiements notés à la main par la console (`accord`) ;
 * - le journal de l'abonnement : essai, formule accordée, révocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_abonnement', function (Blueprint $table) {
            $table->string('reference', 100)->nullable()->after('moyen');
            $table->string('decide_par_nom', 120)->nullable()->after('decide_par');
            // Paiement noté par la console en accordant une formule, sans demande du client.
            $table->boolean('accord')->default(false)->after('statut');
        });

        Schema::table('abonnements', function (Blueprint $table) {
            $table->string('accorde_par_nom', 120)->nullable()->after('accorde_par');
        });

        Schema::create('journal_abonnement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->string('type', 12); // essai, accord, revocation
            $table->string('formule', 20)->nullable();
            $table->date('debut')->nullable();
            $table->date('fin')->nullable();
            $table->unsignedInteger('montant')->nullable();
            $table->string('devise', 3)->nullable();
            $table->string('moyen', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('demande_id')->nullable()->constrained('demandes_abonnement')->nullOnDelete();
            $table->foreignId('par_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->string('par_nom', 120)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['etablissement_id', 'created_at']);
        });

        // Les essais déjà commencés entrent au journal.
        foreach (DB::table('abonnements')->where('formule', 'essai')->get() as $a) {
            DB::table('journal_abonnement')->insert([
                'etablissement_id' => $a->etablissement_id,
                'type' => 'essai',
                'formule' => 'essai',
                'debut' => $a->debut,
                'fin' => $a->fin,
                'created_at' => $a->created_at,
                'updated_at' => $a->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_abonnement');
        Schema::table('abonnements', fn (Blueprint $table) => $table->dropColumn('accorde_par_nom'));
        Schema::table('demandes_abonnement', fn (Blueprint $table) => $table->dropColumn(['reference', 'decide_par_nom', 'accord']));
    }
};
