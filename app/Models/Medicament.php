<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Medicament extends Model
{
    protected $fillable = [
        'etablissement_id',
        'nom',
        'stock',
        'prix_unitaire',
        'seuil_alerte',
        'date_expiration',
        'actif',
    ];

    protected $casts = [
        'date_expiration' => 'date:d/m/Y',
        'actif' => 'boolean',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
