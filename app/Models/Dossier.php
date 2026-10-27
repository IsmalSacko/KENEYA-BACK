<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Le dossier médical : il suit la personne d'un établissement à l'autre
 * (ou, sans compte, reste dans l'établissement qui l'a ouvert).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $personne_id
 * @property int|null $etablissement_id
 * @property int|null $responsable_id dossier de la mère, pour un enfant
 * @property string $nom
 * @property string|null $telephone
 * @property string|null $adresse
 * @property Carbon|null $date_naissance
 * @property bool $naissance_estimee
 * @property string|null $sexe
 * @property string|null $groupe_sanguin
 * @property string|null $allergies
 * @property string|null $antecedents
 * @property string|null $traitements
 * @property Carbon|null $rappel_vaccins_le
 * @property-read Collection<int, ExamenClinique> $examens
 */
class Dossier extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'personne_id',
        'etablissement_id',
        'responsable_id',
        'nom',
        'telephone',
        'adresse',
        'date_naissance',
        'naissance_estimee',
        'sexe',
        'groupe_sanguin',
        'allergies',
        'antecedents',
        'traitements',
        'rappel_vaccins_le',
    ];

    protected $casts = ['naissance_estimee' => 'boolean', 'date_naissance' => 'date:Y-m-d', 'rappel_vaccins_le' => 'date:Y-m-d'];

    /** @return HasMany<ExamenClinique, $this> */
    public function examens(): HasMany
    {
        return $this->hasMany(ExamenClinique::class)->latest();
    }
}
