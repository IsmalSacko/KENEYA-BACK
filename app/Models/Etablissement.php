<?php

namespace App\Models;

use App\Models\Consultation;
use App\Models\Medicament;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etablissement extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'type',
        'telephone',
        'actif',
        'adresse',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'etablissement_user', 'etablissement_id', 'user_id');
    }
    public function consultations()
    {
        return $this->hasMany(Consultation::class);
    }
    public function patients()
    {
        return $this->hasMany(Patient::class);
    }

    public function medicaments()
    {
        return $this->hasMany(Medicament::class);
    }



}
