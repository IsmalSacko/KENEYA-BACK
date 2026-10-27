<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Le passage du patient ce jour-là : ses tickets, sa facture unique, ses
 * paiements.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $personne_id
 * @property int|null $patient_id
 * @property int|null $dossier_id
 * @property string|null $nom
 * @property string|null $telephone
 * @property string $devise
 * @property string $statut
 * @property string $nature
 * @property bool $credit
 * @property int $total
 * @property int $paye
 * @property int|null $assureur_id
 * @property string|null $numero_assure
 * @property int|null $taux_couverture
 * @property int $part_assureur
 * @property array<string, int|float>|null $constantes
 * @property Carbon|null $constantes_at
 * @property int|null $constantes_par
 * @property-read Assureur|null $assureur
 * @property Carbon $ouverte_at
 * @property Carbon|null $cloturee_at
 * @property-read Collection<int, VisiteLigne> $lignes
 * @property-read Collection<int, Encaissement> $encaissements
 * @property-read Collection<int, Ticket> $tickets
 */
class Visite extends Model
{
    use HasUuid;

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'personne_id',
        'patient_id',
        'dossier_id',
        'nom',
        'telephone',
        'devise',
        'statut',
        'total',
        'paye',
        'assureur_id',
        'numero_assure',
        'taux_couverture',
        'part_assureur',
        'constantes',
        'constantes_at',
        'constantes_par',
        'ouverte_at',
        'cloturee_at',
        'nature',
        'credit',
    ];

    protected $casts = ['credit' => 'boolean', 'total' => 'integer', 'paye' => 'integer', 'part_assureur' => 'integer', 'taux_couverture' => 'integer', 'constantes' => 'array', 'constantes_at' => 'datetime', 'ouverte_at' => 'datetime', 'cloturee_at' => 'datetime'];

    /** @return BelongsTo<Assureur, $this> */
    public function assureur(): BelongsTo
    {
        return $this->belongsTo(Assureur::class)->withTrashed();
    }

    /** Ce que le patient doit encore : total, moins l'assureur, moins le payé. */
    public function reste(): int
    {
        return max(0, $this->total - $this->part_assureur - $this->paye);
    }

    /** @return HasMany<VisiteLigne, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(VisiteLigne::class)->orderBy('id');
    }

    /** @return HasMany<Encaissement, $this> */
    public function encaissements(): HasMany
    {
        return $this->hasMany(Encaissement::class)->orderBy('id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('emis_at');
    }
}
