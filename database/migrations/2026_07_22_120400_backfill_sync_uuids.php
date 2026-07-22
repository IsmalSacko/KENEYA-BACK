<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'etablissements',
        'users',
        'patients',
        'medicaments',
        'consultations',
        'ventes_pharmacie',
        'vente_pharmacie_articles',
        'mouvement_stocks',
        'paiements',
        'journal_audits',
    ];

    public function up(): void
    {
        $rev = 0;

        // Remplit uuid + attribue une sync_revision globale croissante aux lignes
        // déjà présentes, afin qu'elles soient récupérées dès la première synchro
        // (le pull filtre sur sync_revision > curseur, sans cas particulier).
        foreach ($this->tables as $table) {
            $ids = DB::table($table)->orderBy('id')->pluck('id');

            foreach ($ids as $id) {
                $rev++;
                DB::table($table)->where('id', $id)->update([
                    'uuid' => Str::uuid()->toString(),
                    'sync_revision' => $rev,
                ]);
            }
        }

        // Positionne la séquence globale au-dessus de tout ce qui existe déjà.
        DB::table('sync_sequences')->where('id', 1)->update([
            'current_rev' => $rev,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Irréversible (données de contenu) — rien à défaire.
    }
};
