<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Une grossesse suivie : DDR, terme prévu, CPN, accouchement.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int $dossier_id
 * @property Carbon $ddr
 * @property Carbon $dpa
 * @property int|null $gestite
 * @property int|null $parite
 * @property string|null $risques
 * @property string $statut en_cours, accouchee, terminee
 * @property string|null $issue fausse_couche, ivg, geu, transferee, perdue_de_vue (terminee)
 * @property Carbon|null $terminee_at
 * @property-read Dossier $dossier
 * @property-read Collection<int, Cpn> $cpns
 * @property-read Accouchement|null $accouchement
 */
class Grossesse extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'dossier_id',
        'ddr',
        'dpa',
        'gestite',
        'parite',
        'risques',
        'statut',
        'issue',
        'terminee_at',
    ];

    protected $casts = ['ddr' => 'date:Y-m-d', 'dpa' => 'date:Y-m-d', 'terminee_at' => 'datetime'];

    /** @return BelongsTo<Dossier, $this> */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    /** @return HasMany<Cpn, $this> */
    public function cpns(): HasMany
    {
        return $this->hasMany(Cpn::class)->orderBy('numero');
    }

    /** @return HasOne<Accouchement, $this> */
    public function accouchement(): HasOne
    {
        return $this->hasOne(Accouchement::class);
    }

    /** Âge de la grossesse en semaines d'aménorrhée, à une date. */
    public function semaines(?Carbon $le = null): int
    {
        return intdiv((int) $this->ddr->diffInDays($le ?? now()), 7);
    }
}
