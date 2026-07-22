<?php

declare(strict_types=1);

namespace App\Sync\Concerns;

use App\Sync\SyncSequenceService;
use Illuminate\Database\Eloquent\Model;

/**
 * Attribue une sync_revision globale à chaque écriture réelle (création,
 * modification effective, ou tombstone de soft delete). Fonctionne quel que
 * soit le chemin d'écriture : API REST en ligne comme moteur de synchronisation.
 */
trait HasSyncRevision
{
    public static function bootHasSyncRevision(): void
    {
        static::saving(function (Model $model): void {
            $dirty = $model->getDirty();
            unset($dirty['sync_revision']);

            // Rien de réel n'a changé (ex. upsert idempotent) → pas de nouvelle
            // révision, pour ne pas générer de trafic de pull inutile.
            if ($model->exists && $dirty === []) {
                return;
            }

            $model->setAttribute('sync_revision', app(SyncSequenceService::class)->next());
        });

        static::deleting(function (Model $model): void {
            // Force delete : la ligne disparaît, inutile de tracer un tombstone.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            // Soft delete : runSoftDelete() ne déclenche pas 'saving', donc on
            // pousse ici une nouvelle révision au tombstone pour qu'il soit pullé.
            $model->setAttribute('sync_revision', app(SyncSequenceService::class)->next());
            $model->saveQuietly();
        });
    }
}
