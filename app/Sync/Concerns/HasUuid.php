<?php

declare(strict_types=1);

namespace App\Sync\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Génère un uuid (identité globale de synchronisation) à la création si absent.
 * Le client offline fournit habituellement son propre uuid ; ce trait garantit
 * qu'une ligne créée côté serveur en reçoit un aussi.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $uuid = $model->getAttribute('uuid');
            if (! is_string($uuid) || $uuid === '') {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}
