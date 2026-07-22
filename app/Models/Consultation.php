<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consultation extends Model
{
    protected $fillable = [
        'etablissement_id',
        'patient_id',
        'medecin_id',
        'motif',
        'montant',
        'statut',
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

    public function medecin()
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }

    public function paiement()
    {
        return $this->morphOne(Paiement::class, 'source');
    }
}
