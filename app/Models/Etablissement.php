<?php

declare(strict_types=1);

namespace App\Models;

use App\Sante\Profil;
use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\EtablissementFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property string $nom
 * @property string $type
 * @property string $pays_code
 * @property string $devise
 * @property string $profil
 * @property list<string> $modules
 * @property-read string|null $itineraire_url
 */
class Etablissement extends Model implements Syncable
{
    /** @use HasFactory<EtablissementFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'nom',
        'type',
        'pays_code',
        'devise',
        'profil',
        'modules',
        'telephone',
        'actif',
        'adresse',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
        'modules' => 'array',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['itineraire_url'];

    protected static function booted(): void
    {
        // Profil, modules, devise et ancien `type` se complètent : l'app 3.x
        // n'envoie que `type`, Ngoni Santé n'envoie que `profil`.
        static::creating(function (Etablissement $etablissement): void {
            $profilSaisi = $etablissement->getAttribute('profil');
            $type = $etablissement->getAttribute('type');
            $profil = (is_string($profilSaisi) ? Profil::tryFrom($profilSaisi) : null)
                ?? Profil::depuisType(is_string($type) ? $type : null);
            $etablissement->setAttribute('profil', $profil->value);

            if ($etablissement->getAttribute('modules') === null) {
                $etablissement->setAttribute('modules', array_map(
                    static fn ($m): string => $m->value,
                    $profil->modulesParDefaut(),
                ));
            }
            if ($etablissement->getAttribute('type') === null) {
                $etablissement->setAttribute('type', Profil::typeLegacy($etablissement->modules));
            }
            if ($etablissement->getAttribute('pays_code') === null) {
                $etablissement->setAttribute('pays_code', Pays::DEFAUT);
            }
            if ($etablissement->getAttribute('devise') === null) {
                $devise = Pays::whereKey($etablissement->pays_code)->value('devise');
                $etablissement->setAttribute('devise', is_string($devise) ? $devise : 'XOF');
            }
        });
    }

    /**
     * URL Google Maps prête à l'emploi vers cet établissement.
     * Le client (url_launcher) l'ouvre ; la position de départ (l'utilisateur)
     * est fournie automatiquement par Google Maps / le GPS de l'appareil.
     *
     * @return Attribute<string|null, never>
     */
    protected function itineraireUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $lat = $this->getAttribute('latitude');
                $lng = $this->getAttribute('longitude');

                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    return null;
                }

                return 'https://www.google.com/maps/dir/?api=1&destination='.$lat.','.$lng;
            },
        );
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'etablissement_user', 'etablissement_id', 'user_id');
    }

    /** @return HasMany<Consultation, $this> */
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /** @return HasMany<Patient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /** @return HasMany<Medicament, $this> */
    public function medicaments(): HasMany
    {
        return $this->hasMany(Medicament::class);
    }
}
