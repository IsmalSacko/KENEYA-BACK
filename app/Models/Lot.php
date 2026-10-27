<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un lot reçu du grossiste : numéro, péremption, quantité restante (unités).
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $medicament_id
 * @property int|null $fournisseur_id
 * @property string|null $numero
 * @property Carbon|null $peremption
 * @property int $quantite
 * @property int $quantite_recue
 * @property string|null $prix_achat
 */
class Lot extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'medicament_id',
        'fournisseur_id',
        'numero',
        'peremption',
        'quantite',
        'quantite_recue',
        'prix_achat',
    ];

    protected $casts = ['peremption' => 'date:Y-m-d', 'quantite' => 'integer', 'quantite_recue' => 'integer'];

    protected $hidden = ['id', 'etablissement_id', 'medicament_id', 'fournisseur_id'];

    /** @return BelongsTo<Medicament, $this> */
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class)->withTrashed();
    }
}
