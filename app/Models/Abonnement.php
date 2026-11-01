<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * L'abonnement d'un établissement : sa formule et jusqu'à quand. Sans
 * échéance quand la plateforme l'a accordé ainsi.
 *
 * @property int $id
 * @property int $etablissement_id
 * @property string $formule
 * @property Carbon $debut
 * @property Carbon|null $fin
 * @property bool $manuel
 * @property int|null $accorde_par
 * @property string|null $accorde_par_nom quand c'est la console : « Console · Ismaila SACKO »
 * @property string|null $note
 * @property string|null $apple_transaction_originale l'abonnement Apple qui le paie : seule sa fin chez Apple l'arrête
 * @property-read Etablissement $etablissement
 */
class Abonnement extends Model
{
    protected $fillable = ['etablissement_id', 'formule', 'debut', 'fin', 'manuel', 'accorde_par', 'accorde_par_nom', 'note', 'apple_transaction_originale'];

    protected $casts = ['debut' => 'date', 'fin' => 'date', 'manuel' => 'boolean'];

    /** Le jour de fin compte en entier. */
    public function enCours(): bool
    {
        return $this->fin === null || ! $this->fin->endOfDay()->isPast();
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
