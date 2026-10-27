<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne d'ordonnance : le produit tel que prescrit, la posologie, la
 * quantité, et ce qui en a déjà été délivré.
 *
 * @property int $id
 * @property string $uuid
 * @property int $ordonnance_id
 * @property int|null $medicament_id
 * @property string $produit
 * @property string|null $posologie
 * @property int $quantite
 * @property int $quantite_delivree
 */
class OrdonnanceLigne extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'ordonnance_id',
        'medicament_id',
        'produit',
        'posologie',
        'quantite',
        'quantite_delivree',
    ];

    protected $casts = ['quantite' => 'integer', 'quantite_delivree' => 'integer'];
}
