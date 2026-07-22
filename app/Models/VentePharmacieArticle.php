<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentePharmacieArticle extends Model
{
    protected $fillable = [
        'vente_pharmacie_id',
        'medicament_id',
        'quantite',
        'prix_unitaire',
        'prix_total',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<VentesPharmacie, $this> */
    public function ventePharmacie(): BelongsTo
    {
        return $this->belongsTo(VentesPharmacie::class);
    }

    /** @return BelongsTo<Medicament, $this> */
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
}
