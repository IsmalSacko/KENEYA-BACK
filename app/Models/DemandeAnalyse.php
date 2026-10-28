<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une analyse demandée et son résultat (nom, unité et normes recopiés).
 *
 * @property int $id
 * @property string $uuid
 * @property int $demande_id
 * @property int|null $analyse_id
 * @property string $nom
 * @property string $type_resultat
 * @property string|null $unite
 * @property string|null $normale
 * @property string|null $resultat
 * @property float|null $valeur
 * @property bool|null $anormal
 * @property string|null $commentaire
 * @property list<array{ancien: string|null, motif: string, par: string|null, le: string}>|null $corrections résultats validés puis corrigés
 * @property int|null $saisi_par
 * @property Carbon|null $saisi_at
 * @property-read Analyse|null $analyse
 */
class DemandeAnalyse extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'demande_id',
        'analyse_id',
        'nom',
        'type_resultat',
        'unite',
        'normale',
        'resultat',
        'valeur',
        'anormal',
        'commentaire',
        'saisi_par',
        'saisi_at',
        'corrections',
    ];

    protected $casts = ['valeur' => 'float', 'anormal' => 'boolean', 'saisi_at' => 'datetime', 'corrections' => 'array'];

    /** @return BelongsTo<Analyse, $this> */
    public function analyse(): BelongsTo
    {
        return $this->belongsTo(Analyse::class)->withTrashed();
    }
}
