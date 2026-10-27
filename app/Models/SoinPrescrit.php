<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une ligne du plan de soins : produit, dose, voie, horaires de la journée.
 *
 * @property int $id
 * @property string $uuid
 * @property int $hospitalisation_id
 * @property int|null $prescripteur_id
 * @property string $produit
 * @property string|null $dose
 * @property string $voie
 * @property list<string> $horaires
 * @property Carbon $debut
 * @property Carbon|null $fin
 * @property string|null $instructions
 * @property Carbon|null $arrete_at
 * @property-read User|null $prescripteur
 */
class SoinPrescrit extends Model
{
    use HasUuid;

    protected $table = 'soins_prescrits';

    protected $fillable = [
        'uuid',
        'hospitalisation_id',
        'prescripteur_id',
        'produit',
        'dose',
        'voie',
        'horaires',
        'debut',
        'fin',
        'instructions',
        'arrete_at',
    ];

    protected $casts = ['horaires' => 'array', 'debut' => 'date:Y-m-d', 'fin' => 'date:Y-m-d', 'arrete_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }
}
