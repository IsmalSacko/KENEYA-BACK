<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\MedicamentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un produit de la pharmacie. `stock` est le total en unités (comprimés,
 * flacons ; une boîte = `conditionnement` unités) ; le détail est dans les
 * lots.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $nom
 * @property string|null $dci
 * @property string $rayon
 * @property string|null $prix_achat
 * @property string|null $forme
 * @property string|null $dosage
 * @property int $conditionnement
 * @property bool $vente_unite
 * @property numeric-string|null $prix_unite
 * @property numeric-string $prix_unitaire
 * @property bool $ordonnance_requise
 * @property int $stock
 * @property int $seuil_alerte
 * @property bool $actif
 * @property-read Collection<int, Lot> $lots
 */
class Medicament extends Model implements Syncable
{
    /** @use HasFactory<MedicamentFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'etablissement_id',
        'uuid',
        'nom',
        'dci',
        'forme',
        'dosage',
        'conditionnement',
        'vente_unite',
        'prix_unite',
        'ordonnance_requise',
        'code_barres',
        'rayon',
        'prix_achat',
        'stock',
        'prix_unitaire',
        'seuil_alerte',
        'date_expiration',
        'actif',
    ];

    protected $casts = [
        'date_expiration' => 'date:d/m/Y',
        'actif' => 'boolean',
        'vente_unite' => 'boolean',
        'ordonnance_requise' => 'boolean',
        'conditionnement' => 'integer',
        'stock' => 'integer',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
