<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Le journal de l'abonnement d'un établissement : l'essai, les formules
 * accordées à la main, les révocations. Les paiements sont les demandes.
 *
 * @property int $id
 * @property int $etablissement_id
 * @property string $type essai, accord, revocation
 * @property string|null $formule
 * @property Carbon|null $debut
 * @property Carbon|null $fin
 * @property int|null $montant
 * @property string|null $devise
 * @property string|null $moyen
 * @property string|null $reference
 * @property int|null $demande_id
 * @property int|null $par_id
 * @property string|null $par_nom
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read DemandeAbonnement|null $demande
 * @property-read Personne|null $par
 */
class JournalAbonnement extends Model
{
    protected $table = 'journal_abonnement';

    protected $fillable = [
        'etablissement_id', 'type', 'formule', 'debut', 'fin', 'montant', 'devise', 'moyen', 'reference',
        'demande_id', 'par_id', 'par_nom', 'note',
    ];

    protected $casts = ['debut' => 'date', 'fin' => 'date', 'montant' => 'integer'];

    /** @return BelongsTo<DemandeAbonnement, $this> */
    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeAbonnement::class, 'demande_id');
    }

    /** @return BelongsTo<Personne, $this> */
    public function par(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'par_id');
    }
}
