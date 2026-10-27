<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Une personne = un numéro de téléphone. C'est le compte de l'app : l'espace
 * « Ma santé » est à elle ; chaque établissement où elle travaille lui ouvre
 * un espace pro, à travers un membre (App\Models\User).
 *
 * @property int $id
 * @property string $uuid
 * @property string $nom
 * @property string $pays_code
 * @property string $telephone
 * @property string $pin
 * @property bool $pro
 * @property string|null $carte_jeton
 * @property string|null $accord_jeton
 * @property Carbon|null $accord_expire_at
 * @property Carbon|null $date_naissance
 * @property string|null $sexe
 * @property Carbon|null $telephone_verifie_at
 * @property bool $actif
 */
class Personne extends Authenticatable implements JWTSubject
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'pro',
        'nom',
        'pays_code',
        'telephone',
        'carte_jeton',
        'accord_jeton',
        'accord_expire_at',
        'pin',
        'telephone_verifie_at',
        'date_naissance',
        'sexe',
        'langue',
        'actif',
    ];

    protected $hidden = ['id', 'pin', 'carte_jeton', 'accord_jeton', 'accord_expire_at', 'deleted_at'];

    protected static function booted(): void
    {
        // La carte santé QR porte ce jeton, pas l'identifiant de la personne.
        static::creating(function (Personne $personne): void {
            if ($personne->getAttribute('carte_jeton') === null) {
                $personne->setAttribute('carte_jeton', Str::random(32));
            }
        });
    }

    protected $casts = [
        'pro' => 'boolean',
        'pin' => 'hashed',
        'telephone_verifie_at' => 'datetime',
        'accord_expire_at' => 'datetime',
        'date_naissance' => 'date:Y-m-d',
        'actif' => 'boolean',
    ];

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /** @return BelongsTo<Pays, $this> */
    public function pays(): BelongsTo
    {
        return $this->belongsTo(Pays::class, 'pays_code', 'code');
    }

    /**
     * Ses comptes de membre, un par établissement.
     *
     * @return HasMany<User, $this>
     */
    public function membres(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
