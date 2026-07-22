<?php

declare(strict_types=1);

namespace App\Sync\Contracts;

/**
 * Marque un modèle comme synchronisable hors-ligne.
 *
 * Les modèles concernés utilisent aussi les traits App\Sync\Concerns\HasUuid,
 * App\Sync\Concerns\HasSyncRevision et Illuminate\Database\Eloquent\SoftDeletes.
 * La description de synchronisation (champs, clés étrangères, append-only…) est
 * centralisée dans config/sync.php.
 */
interface Syncable
{
    // Interface marqueur : aucune méthode. La description de synchronisation
    // vit dans config/sync.php et le comportement dans les traits associés.
}
