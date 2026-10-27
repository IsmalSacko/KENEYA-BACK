<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le numéro de dossier, propre à chaque centre : l'année d'inscription et
 * quatre chiffres au hasard (« 26-4821 »). Le dossier est partagé entre les
 * centres, le numéro non : chacun garde le sien sur son registre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numeros_dossier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->string('numero', 12);
            $table->timestamps();
            $table->unique(['etablissement_id', 'numero']);
            $table->unique(['etablissement_id', 'dossier_id']);
        });

        $this->numeroterLesPatientsDejaLa();
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros_dossier');
    }

    /** Chaque patient déjà reçu : l'année de sa première venue dans le centre. */
    private function numeroterLesPatientsDejaLa(): void
    {
        $arrivees = [];
        foreach (DB::table('dossiers')->whereNotNull('etablissement_id')->get(['id', 'etablissement_id', 'created_at']) as $d) {
            $arrivees[$d->etablissement_id.'-'.$d->id] = [(int) $d->etablissement_id, (int) $d->id, (string) $d->created_at];
        }
        $visites = DB::table('visites')->whereNotNull('dossier_id')
            ->selectRaw('etablissement_id, dossier_id, MIN(ouverte_at) as premiere')
            ->groupBy('etablissement_id', 'dossier_id')->get();
        foreach ($visites as $v) {
            $cle = $v->etablissement_id.'-'.$v->dossier_id;
            if (! isset($arrivees[$cle]) || (string) $v->premiere < $arrivees[$cle][2]) {
                $arrivees[$cle] = [(int) $v->etablissement_id, (int) $v->dossier_id, (string) $v->premiere];
            }
        }

        $pris = [];
        foreach ($arrivees as [$etab, $dossier, $le]) {
            $annee = substr((string) (strtotime($le) !== false ? date('y', (int) strtotime($le)) : date('y')), 0, 2);
            do {
                $numero = $annee.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            } while (isset($pris[$etab][$numero]));
            $pris[$etab][$numero] = true;
            DB::table('numeros_dossier')->insert([
                'etablissement_id' => $etab, 'dossier_id' => $dossier, 'numero' => $numero,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
