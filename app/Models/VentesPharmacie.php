<?php

namespace App\Models;

use App\Models\Etablissement;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\VentePharmacieArticle;
use Illuminate\Database\Eloquent\Model;

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

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function articles()
    {
        return $this->hasMany(VentePharmacieArticle::class, 'vente_pharmacie_id');
    }

    public function paiement()
    {
        return $this->morphOne(Paiement::class, 'source');
    }
}
