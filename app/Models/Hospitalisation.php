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
 * Un séjour : admission sur un lit, plan de soins, sortie.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $dossier_id
 * @property int|null $visite_id
 * @property int|null $lit_id
 * @property int|null $medecin_id
 * @property string $motif
 * @property string $statut en_cours, sortie
 * @property Carbon $entree_at
 * @property Carbon|null $sortie_at
 * @property string|null $mode_sortie
 * @property string|null $resume_sortie
 * @property-read Dossier $dossier
 * @property-read Lit|null $lit
 * @property-read User|null $medecin
 * @property-read Etablissement $etablissement
 * @property-read Collection<int, SoinPrescrit> $soins
 */
class Hospitalisation extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'dossier_id',
        'visite_id',
        'lit_id',
        'medecin_id',
        'motif',
        'statut',
        'entree_at',
        'sortie_at',
        'mode_sortie',
        'resume_sortie',
    ];

    protected $casts = ['entree_at' => 'datetime', 'sortie_at' => 'datetime'];

    /** @return BelongsTo<Dossier, $this> */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /** @return BelongsTo<Lit, $this> */
    public function lit(): BelongsTo
    {
        return $this->belongsTo(Lit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return HasMany<SoinPrescrit, $this> */
    public function soins(): HasMany
    {
        return $this->hasMany(SoinPrescrit::class)->orderBy('id');
    }
}
