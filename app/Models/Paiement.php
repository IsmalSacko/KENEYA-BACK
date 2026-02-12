<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    protected $fillable = [
        'etablissement_id',
        'source_type',
        'source_id',
        'montant',
        'mode_paiement',
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

    public function source()
    {
        return $this->morphTo();
    }
}
