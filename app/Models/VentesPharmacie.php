<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class VentesPharmacie extends Model
{
    protected $table = 'ventes_pharmacie';

    protected $fillable = [
        'etablissement_id',
        'patient_id',
        'montant_total',
        'mode_paiement',
        'statut_sync',
        'reference_locale',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<VentePharmacieArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(VentePharmacieArticle::class, 'vente_pharmacie_id');
    }

    /** @return MorphOne<Paiement, $this> */
    public function paiement(): MorphOne
    {
        return $this->morphOne(Paiement::class, 'source');
    }
}
