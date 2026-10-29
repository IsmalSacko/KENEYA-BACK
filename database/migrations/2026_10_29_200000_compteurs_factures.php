<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures se suivent (« F26-0001 », « F26-0002 »…) dans l'établissement
 * et l'année, comme l'exige la loi, au lieu d'un numéro au hasard. Les
 * factures déjà numérotées sont renumérotées dans l'ordre où elles ont été
 * créées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compteurs_factures', function (Blueprint $table) {
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier')->default(0);
            $table->primary(['etablissement_id', 'annee']);
        });

        $visites = DB::table('visites')->whereNotNull('numero_facture')->orderBy('created_at')->orderBy('id')->get(['id', 'etablissement_id', 'numero_facture']);
        // Libère les numéros avant de les redonner : l'index unique ne bloque pas.
        foreach ($visites as $v) {
            DB::table('visites')->where('id', $v->id)->update(['numero_facture' => 'R'.$v->id]);
        }
        $compteurs = [];
        foreach ($visites as $v) {
            $an = (int) substr((string) $v->numero_facture, 1, 2);
            $cle = $v->etablissement_id.'-'.$an;
            $compteurs[$cle] = ($compteurs[$cle] ?? 0) + 1;
            DB::table('visites')->where('id', $v->id)->update([
                'numero_facture' => sprintf('F%02d-%04d', $an, $compteurs[$cle]),
            ]);
        }
        foreach ($compteurs as $cle => $dernier) {
            [$etablissement, $an] = explode('-', $cle);
            DB::table('compteurs_factures')->insert([
                'etablissement_id' => (int) $etablissement, 'annee' => 2000 + (int) $an, 'dernier' => $dernier,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compteurs_factures');
    }
};
