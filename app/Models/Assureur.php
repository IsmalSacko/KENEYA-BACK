<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un assureur avec qui l'établissement travaille.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property int $taux pourcentage pris en charge
 * @property int|null $plafond par visite, en unités mineures
 * @property bool $actif
 */
class Assureur extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = ['uuid', 'etablissement_id', 'nom', 'taux', 'plafond', 'actif'];

    protected $casts = ['taux' => 'integer', 'plafond' => 'integer', 'actif' => 'boolean'];
}
