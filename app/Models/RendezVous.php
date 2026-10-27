<?php

declare(strict_types=1);

namespace App\Models;

use App\Sync\Concerns\HasSyncRevision;
use App\Sync\Concerns\HasUuid;
use App\Sync\Contracts\Syncable;
use Database\Factories\RendezVousFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Un rendez-vous : réservé sur un créneau d'agenda (Ngoni Santé), ou simple
 * demande à une date souhaitée (app 3.x, page publique).
 *
 * @property int $id
 * @property string $uuid
 * @property int $etablissement_id
 * @property int|null $agenda_id
 * @property int|null $personne_id
 * @property int|null $dossier_id
 * @property string $patient_nom
 * @property string $patient_telephone
 * @property Carbon $date_souhaitee
 * @property Carbon|null $debut
 * @property Carbon|null $fin
 * @property string|null $motif
 * @property string $statut
 * @property string $canal
 * @property Carbon|null $rappel_veille_at
 * @property Carbon|null $rappel_proche_at
 * @property Carbon|null $arrivee_at
 * @property int|null $ticket_id
 * @property-read Agenda|null $agenda
 * @property-read Etablissement $etablissement
 */
class RendezVous extends Model implements Syncable
{
    /** @use HasFactory<RendezVousFactory> */
    use HasFactory, HasSyncRevision, HasUuid, SoftDeletes;

    protected $table = 'rendez_vous';

    protected $fillable = [
        'uuid',
        'etablissement_id',
        'medecin_id',
        'patient_nom',
        'patient_telephone',
        'date_souhaitee',
        'motif',
        'statut',
        'agenda_id',
        'personne_id',
        'dossier_id',
        'debut',
        'fin',
        'canal',
        'rappel_veille_at',
        'rappel_proche_at',
        'arrivee_at',
        'ticket_id',
    ];

    /** Statuts où le créneau est pris. */
    public const OCCUPE = ['demande', 'confirme', 'honore'];

    protected $casts = [
        'date_souhaitee' => 'datetime',
        'debut' => 'datetime',
        'fin' => 'datetime',
        'rappel_veille_at' => 'datetime',
        'rappel_proche_at' => 'datetime',
        'arrivee_at' => 'datetime',
        'created_at' => 'datetime:d/m/Y H:i',
        'updated_at' => 'datetime:d/m/Y H:i',
    ];

    /** @return BelongsTo<Etablissement, $this> */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /** @return BelongsTo<Agenda, $this> */
    public function agenda(): BelongsTo
    {
        return $this->belongsTo(Agenda::class)->withTrashed();
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }
}
