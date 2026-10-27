<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une consultation prénatale.
 *
 * @property int $id
 * @property string $uuid
 * @property int $grossesse_id
 * @property int $etablissement_id
 * @property int|null $praticien_id
 * @property int $numero
 * @property int $semaines
 * @property float|null $poids
 * @property int|null $ta_sys
 * @property int|null $ta_dia
 * @property int|null $hauteur_uterine
 * @property int|null $bcf
 * @property string|null $presentation
 * @property bool|null $oedemes
 * @property string|null $albuminurie
 * @property int|null $tpi
 * @property int|null $vat
 * @property bool $fer
 * @property bool $moustiquaire
 * @property string|null $observations
 * @property Carbon|null $prochain_rdv
 * @property Carbon|null $rappel_at
 * @property Carbon|null $created_at
 * @property-read Grossesse $grossesse
 * @property-read Etablissement $etablissement
 * @property-read User|null $praticien
 */
class Cpn extends Model
{
    use HasUuid;

    protected $table = 'cpn';

    protected $fillable = [
        'uuid',
        'grossesse_id',
        'etablissement_id',
        'praticien_id',
        'numero',
        'semaines',
        'poids',
        'ta_sys',
        'ta_dia',
        'hauteur_uterine',
        'bcf',
        'presentation',
        'oedemes',
        'albuminurie',
        'tpi',
        'vat',
        'fer',
        'moustiquaire',
        'observations',
        'prochain_rdv',
        'rappel_at',
    ];

    protected $casts = [
        'poids' => 'float',
        'oedemes' => 'boolean',
        'fer' => 'boolean',
        'moustiquaire' => 'boolean',
        'prochain_rdv' => 'date:Y-m-d',
        'rappel_at' => 'datetime',
    ];

    /** @return BelongsTo<Grossesse, $this> */
    public function grossesse(): BelongsTo
    {
        return $this->belongsTo(Grossesse::class);
    }

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function praticien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'praticien_id');
    }
}
