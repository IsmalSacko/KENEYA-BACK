<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une formule d'abonnement (essai, Essentiel, Pro, Hôpital) : les modules
 * qu'elle ouvre, ses fonctions en plus (écrans, rapport), la taille de
 * l'équipe et ses tarifs. Réglable depuis la console, sans redéployer.
 *
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property string|null $description
 * @property list<string> $modules
 * @property list<string> $fonctions
 * @property int $jours_essai
 * @property int|null $max_membres
 * @property bool $actif
 * @property int $ordre
 */
class Formule extends Model
{
    public const ESSAI = 'essai';

    protected $fillable = ['code', 'nom', 'description', 'modules', 'fonctions', 'jours_essai', 'max_membres', 'actif', 'ordre'];

    protected $casts = [
        'modules' => 'array',
        'fonctions' => 'array',
        'jours_essai' => 'integer',
        'max_membres' => 'integer',
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    /** @return HasMany<FormuleTarif, $this> */
    public function tarifs(): HasMany
    {
        return $this->hasMany(FormuleTarif::class)->orderBy('mois');
    }
}
