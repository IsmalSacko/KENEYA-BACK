<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une prise du plan de soins, faite (bracelet scanné), refusée ou omise.
 *
 * @property int $id
 * @property string $uuid
 * @property int $soin_id
 * @property int $hospitalisation_id
 * @property Carbon $prevue_at
 * @property string $statut faite, refusee, omise
 * @property Carbon $faite_at
 * @property int|null $par
 * @property bool $bracelet_verifie
 * @property string|null $commentaire
 * @property-read User|null $soignant
 */
class Administration extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'soin_id',
        'hospitalisation_id',
        'prevue_at',
        'statut',
        'faite_at',
        'par',
        'bracelet_verifie',
        'commentaire',
    ];

    protected $casts = ['prevue_at' => 'datetime', 'faite_at' => 'datetime', 'bracelet_verifie' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function soignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'par');
    }
}
