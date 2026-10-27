<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une chambre (ou une salle commune) et ses lits ; la nuit se facture par
 * l'acte du tarif.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property string|null $service
 * @property int|null $tarif_id
 * @property bool $actif
 * @property-read Tarif|null $tarif
 * @property-read Collection<int, Lit> $lits
 */
class Chambre extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = ['uuid', 'etablissement_id', 'nom', 'service', 'tarif_id', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    /** @return HasMany<Lit, $this> */
    public function lits(): HasMany
    {
        return $this->hasMany(Lit::class)->orderBy('numero');
    }

    /** @return BelongsTo<Tarif, $this> */
    public function tarif(): BelongsTo
    {
        return $this->belongsTo(Tarif::class);
    }
}
