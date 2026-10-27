<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne de la facture de la visite.
 *
 * @property int $id
 * @property string $uuid
 * @property int $visite_id
 * @property int|null $tarif_id
 * @property string $libelle
 * @property int $quantite
 * @property int $prix_unitaire
 * @property int $montant
 * @property int|null $ajoute_par
 */
class VisiteLigne extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'visite_id',
        'tarif_id',
        'libelle',
        'quantite',
        'prix_unitaire',
        'montant',
        'ajoute_par',
    ];

    protected $casts = ['quantite' => 'integer', 'prix_unitaire' => 'integer', 'montant' => 'integer'];
}
