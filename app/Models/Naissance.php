<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un enfant né de l'accouchement ; vivant, il a son propre dossier.
 *
 * @property int $id
 * @property string $uuid
 * @property int $accouchement_id
 * @property int|null $dossier_id
 * @property string $sexe
 * @property bool $vivant
 * @property int|null $poids_g
 * @property int|null $taille_cm
 * @property int|null $apgar1
 * @property int|null $apgar5
 * @property-read Dossier|null $dossier
 */
class Naissance extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'accouchement_id',
        'dossier_id',
        'sexe',
        'vivant',
        'poids_g',
        'taille_cm',
        'apgar1',
        'apgar5',
    ];

    protected $casts = ['vivant' => 'boolean'];

    /** @return BelongsTo<Dossier, $this> */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
