<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un acte de l'établissement et son prix : « Consultation générale 2 000 ».
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property string $categorie
 * @property int $prix
 * @property bool $actif
 */
class Tarif extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'nom',
        'categorie',
        'prix',
        'actif',
    ];

    protected $casts = ['prix' => 'integer', 'actif' => 'boolean'];
}
