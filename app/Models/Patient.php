<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    //

    protected $fillable = [
        'etablissement_id',
        'nom',
        'telephone',
        'adresse',
    ];

    protected $casts = [
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];


    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }
    public function consultations()
    {
        return $this->hasMany(Consultation::class);
    }

    public function ventesPharmacie()
    {
        return $this->hasMany(VentesPharmacie::class);
    }
}
