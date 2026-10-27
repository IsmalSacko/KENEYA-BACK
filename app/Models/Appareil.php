<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un appareil de l'établissement sans compte personnel : écran TV de la
 * salle d'attente ou borne d'accueil. Il s'appaire avec un code (ou son QR)
 * créé dans l'espace pro, et reçoit un jeton révocable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property string $mode
 * @property string $nom
 * @property string|null $jeton
 * @property string|null $code_appairage
 * @property Carbon|null $code_expire_at
 * @property list<string>|null $services
 * @property Carbon|null $appaire_at
 * @property Carbon|null $dernier_contact_at
 * @property Carbon|null $revoque_at
 * @property-read Etablissement $etablissement
 */
class Appareil extends Model
{
    use HasUuid;

    public const ECRAN = 'ecran';

    public const BORNE = 'borne';

    protected $fillable = [
        'uuid', 'etablissement_id', 'mode', 'nom', 'jeton', 'code_appairage', 'code_expire_at',
        'services', 'appaire_at', 'dernier_contact_at', 'revoque_at',
    ];

    protected $casts = [
        'services' => 'array',
        'code_expire_at' => 'datetime',
        'appaire_at' => 'datetime',
        'dernier_contact_at' => 'datetime',
        'revoque_at' => 'datetime',
    ];

    protected $hidden = ['id', 'etablissement_id', 'jeton', 'code_appairage'];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }
}
