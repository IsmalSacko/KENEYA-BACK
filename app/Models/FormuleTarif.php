<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Le prix d'une formule pour 1, 3, 6 ou 12 mois.
 *
 * @property int $formule_id
 * @property int $mois
 * @property int $montant
 * @property string $devise
 */
class FormuleTarif extends Model
{
    protected $fillable = ['formule_id', 'mois', 'montant', 'devise'];

    protected $casts = ['mois' => 'integer', 'montant' => 'integer'];
}
