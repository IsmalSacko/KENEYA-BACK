<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une demande d'analyses : demandée, prélevée, puis validée par le labo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $dossier_id
 * @property int|null $visite_id
 * @property int|null $prescripteur_id
 * @property string $statut demandee, prelevee, validee, annulee
 * @property bool $urgent
 * @property string|null $renseignements
 * @property string|null $motif_annulation
 * @property string|null $rejet_motif échantillon rejeté : à prélever de nouveau
 * @property Carbon|null $rejete_at
 * @property Carbon|null $preleve_at
 * @property int|null $preleve_par
 * @property Carbon|null $valide_at
 * @property int|null $valide_par
 * @property Carbon|null $created_at
 * @property-read Dossier $dossier
 * @property-read Etablissement $etablissement
 * @property-read User|null $prescripteur
 * @property-read User|null $validateur
 * @property-read Collection<int, DemandeAnalyse> $lignes
 */
class DemandeExamen extends Model
{
    use HasUuid;

    protected $table = 'demandes_examen';

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'dossier_id',
        'visite_id',
        'prescripteur_id',
        'statut',
        'urgent',
        'renseignements',
        'motif_annulation',
        'rejet_motif',
        'rejete_at',
        'preleve_at',
        'preleve_par',
        'valide_at',
        'valide_par',
    ];

    protected $casts = ['urgent' => 'boolean', 'preleve_at' => 'datetime', 'valide_at' => 'datetime', 'rejete_at' => 'datetime'];

    /** @return HasMany<DemandeAnalyse, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(DemandeAnalyse::class, 'demande_id')->orderBy('id');
    }

    /** @return BelongsTo<Dossier, $this> */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }

    /** @return BelongsTo<User, $this> */
    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
