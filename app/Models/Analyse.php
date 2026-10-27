<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une analyse du catalogue du laboratoire, avec ses valeurs normales.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $code
 * @property string $nom
 * @property string $categorie
 * @property string $type_resultat nombre, choix ou texte
 * @property string|null $unite
 * @property float|null $normale_min
 * @property float|null $normale_max
 * @property list<string>|null $choix
 * @property string|null $choix_normal
 * @property int|null $tarif_id
 * @property bool $actif
 * @property-read Tarif|null $tarif
 */
class Analyse extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'code',
        'nom',
        'categorie',
        'type_resultat',
        'unite',
        'normale_min',
        'normale_max',
        'choix',
        'choix_normal',
        'tarif_id',
        'actif',
    ];

    protected $casts = [
        'normale_min' => 'float',
        'normale_max' => 'float',
        'choix' => 'array',
        'actif' => 'boolean',
    ];

    /** @return BelongsTo<Tarif, $this> */
    public function tarif(): BelongsTo
    {
        return $this->belongsTo(Tarif::class);
    }

    /** « 0,7 – 1,1 g/L », « Négatif » */
    public function normale(): ?string
    {
        if ($this->type_resultat === 'choix') {
            return $this->choix_normal;
        }
        if ($this->normale_min === null && $this->normale_max === null) {
            return null;
        }
        $f = static fn (?float $v): string => $v === null ? '…' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

        return trim($f($this->normale_min).' – '.$f($this->normale_max).' '.($this->unite ?? ''));
    }
}
