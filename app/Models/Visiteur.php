<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un visiteur : qui il vient voir, quand il entre et sort.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $hospitalisation_id
 * @property string $nom
 * @property string|null $telephone
 * @property string|null $piece
 * @property Carbon $entree_at
 * @property Carbon|null $sortie_at
 * @property int|null $enregistre_par
 * @property-read Hospitalisation|null $hospitalisation
 */
class Visiteur extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'hospitalisation_id',
        'nom',
        'telephone',
        'piece',
        'entree_at',
        'sortie_at',
        'enregistre_par',
    ];

    protected $casts = ['entree_at' => 'datetime', 'sortie_at' => 'datetime'];

    /** @return BelongsTo<Hospitalisation, $this> */
    public function hospitalisation(): BelongsTo
    {
        return $this->belongsTo(Hospitalisation::class);
    }
}
