<?php

declare(strict_types=1);

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Séquence de révision globale et monotone.
 *
 * Chaque écriture d'un modèle syncable reçoit une révision unique et croissante.
 * L'incrément se fait sous verrou (FOR UPDATE) pour garantir la monotonie même
 * sous synchronisations concurrentes. Sur SQLite (tests) le verrou est ignoré,
 * ce qui reste correct car les tests ne sont pas concurrents.
 */
class SyncSequenceService
{
    /**
     * Réserve et renvoie la prochaine révision.
     */
    public function next(): int
    {
        return DB::transaction(function (): int {
            $current = $this->currentLocked();
            $next = $current + 1;

            DB::table('sync_sequences')->where('id', 1)->update([
                'current_rev' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });
    }

    /**
     * Valeur courante de la séquence (borne haute d'un pull).
     */
    public function current(): int
    {
        return Cast::int(DB::table('sync_sequences')->where('id', 1)->value('current_rev'));
    }

    private function currentLocked(): int
    {
        $row = DB::table('sync_sequences')->where('id', 1)->lockForUpdate()->first();

        return $row === null ? 0 : Cast::int($row->current_rev);
    }
}
