<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function ventePharmacie()
    {
        return $this->belongsTo(VentesPharmacie::class);
    }

    public function medicament()
    {
        return $this->belongsTo(Medicament::class);
    }
}
