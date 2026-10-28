<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une demande d'abonnement : l'établissement a payé (Orange Money, Wave…)
 * et la plateforme valide. Approuvée, elle devient un reçu.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $demandeur_id
 * @property string $formule
 * @property int $mois
 * @property int $montant
 * @property int|null $montant_demande avant un ajustement à la validation
 * @property string $devise
 * @property string $moyen
 * @property string|null $telephone
 * @property string|null $note
 * @property string|null $preuve_chemin
 * @property string $statut en_attente, approuvee, refusee, annulee
 * @property Carbon|null $decide_at
 * @property int|null $decide_par
 * @property string|null $motif_refus
 * @property string|null $note_validation
 * @property string|null $recu_numero
 * @property Carbon|null $periode_debut
 * @property Carbon|null $periode_fin
 * @property Carbon $created_at
 * @property-read Etablissement $etablissement
 * @property-read Personne|null $demandeur
 */
class DemandeAbonnement extends Model
{
    use HasUuid;

    public const MOYENS = ['orange_money', 'moov_money', 'wave', 'especes', 'virement'];

    protected $table = 'demandes_abonnement';

    protected $fillable = [
        'uuid', 'etablissement_id', 'demandeur_id', 'formule', 'mois', 'montant', 'devise', 'moyen',
        'telephone', 'note', 'preuve_chemin', 'statut', 'decide_at', 'decide_par', 'motif_refus',
        'recu_numero', 'periode_debut', 'periode_fin', 'montant_demande', 'note_validation',
    ];

    protected $casts = [
        'mois' => 'integer',
        'montant' => 'integer',
        'montant_demande' => 'integer',
        'decide_at' => 'datetime',
        'periode_debut' => 'date',
        'periode_fin' => 'date',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<Personne, $this> */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'demandeur_id');
    }
}
