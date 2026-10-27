<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pays où Ngoni Santé fonctionne : devise, indicatif, langues, moyens de
 * paiement. C'est une donnée, jamais du code (servie par GET /api/pays).
 *
 * @property string $code
 * @property string $nom
 * @property string $drapeau
 * @property string $indicatif
 * @property string $devise
 * @property int $decimales
 * @property list<string> $langues
 * @property string $fuseau
 * @property list<string> $moyens_paiement
 * @property bool $supprimer_zero_initial
 * @property bool $actif
 */
class Pays extends Model
{
    protected $table = 'pays';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'nom',
        'drapeau',
        'indicatif',
        'devise',
        'decimales',
        'langues',
        'fuseau',
        'moyens_paiement',
        'supprimer_zero_initial',
        'actif',
        'ordre',
    ];

    protected $casts = [
        'decimales' => 'integer',
        'langues' => 'array',
        'moyens_paiement' => 'array',
        'supprimer_zero_initial' => 'boolean',
        'actif' => 'boolean',
        'ordre' => 'integer',
    ];

    protected $hidden = ['created_at', 'updated_at', 'ordre'];

    /** Pays par défaut (comme Ngoni Caisse). */
    public const DEFAUT = 'ML';
}
