<?php

declare(strict_types=1);

namespace App\Sante\File;

use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\PointService;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Caisse\Caisse;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Telephone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La file d'attente d'un établissement : tickets numérotés par service et
 * par jour, appel du suivant selon les priorités, transferts, attente
 * estimée, état des écrans.
 */
final class FileAttente
{
    /** On prévient le patient quand il ne reste que ce nombre de personnes devant lui. */
    public const BIENTOT = 3;

    public function __construct(
        private readonly EnvoiSms $sms,
        private readonly Caisse $caisse,
    ) {}

    /** La date du jour dans le fuseau du pays de l'établissement. */
    public function aujourdhui(Etablissement $etablissement): string
    {
        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');

        return Carbon::now(is_string($fuseau) ? $fuseau : 'Africa/Bamako')->toDateString();
    }

    /**
     * Émet un ticket. Le numéro suit le dernier du jour dans ce service ; le
     * verrou sur le service évite deux fois le même numéro.
     *
     * @param  array{nom?: string|null, telephone?: string|null, personne_id?: int|null, patient_id?: int|null, dossier_id?: int|null, ticket_precedent_id?: int|null}  $qui
     */
    public function emettre(Service $service, Priorite $priorite, string $canal, array $qui = []): Ticket
    {
        $etablissement = $service->etablissement()->firstOrFail();
        $jour = $this->aujourdhui($etablissement);

        // Numéro saisi à l'accueil (« 76 12 34 56 ») : au format international
        // du pays de l'établissement, pour que le SMS parte.
        $telephone = $qui['telephone'] ?? null;
        if (is_string($telephone) && trim($telephone) !== '') {
            $pays = Pays::find($etablissement->pays_code);
            $qui['telephone'] = $pays !== null ? Telephone::normaliser($telephone, $pays) : $telephone;
        }

        // Un seul ticket en cours par patient et par service : celui qu'il a
        // déjà (pris depuis l'app, ou donné par l'accueil) est repris.
        $personneId = $qui['personne_id'] ?? null;
        $dossierId = $qui['dossier_id'] ?? null;
        if ($personneId !== null || $dossierId !== null) {
            $existant = Ticket::where('service_id', $service->id)->where('jour', $jour)
                ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value])
                ->where(fn ($q) => $q
                    ->when($personneId !== null, fn ($w) => $w->orWhere('personne_id', $personneId))
                    ->when($dossierId !== null, fn ($w) => $w->orWhereIn('visite_id', Visite::where('dossier_id', $dossierId)->select('id'))))
                ->first();
            if ($existant !== null) {
                return $existant;
            }
        }

        // Chaque ticket appartient à une visite : la facture unique du passage.
        $visite = $this->caisse->visitePourTicket($etablissement, $qui);

        // L'acte du service (ex. le ticket de consultation) va sur la facture,
        // une seule fois par visite.
        if ($service->tarif_id !== null
            && $visite->statut === 'ouverte'
            && ! $visite->lignes()->where('tarif_id', $service->tarif_id)->exists()
            && ($tarif = Tarif::find($service->tarif_id)) !== null
            && $tarif->actif) {
            $this->caisse->ajouterLigne($visite, $tarif, null, null, 1, null);
        }

        return DB::transaction(function () use ($service, $priorite, $canal, $qui, $jour, $visite): Ticket {
            Service::whereKey($service->id)->lockForUpdate()->first();
            $dernier = Ticket::where('service_id', $service->id)->where('jour', $jour)->max('numero');
            $numero = (is_numeric($dernier) ? (int) $dernier : 0) + 1;

            return Ticket::create([
                'etablissement_id' => $service->etablissement_id,
                'service_id' => $service->id,
                'jour' => $jour,
                'numero' => $numero,
                'code' => $service->prefixe.str_pad((string) $numero, 3, '0', STR_PAD_LEFT),
                'priorite' => $priorite,
                'statut' => Statut::Emis,
                'canal' => $canal,
                'nom' => $qui['nom'] ?? null,
                'telephone' => $qui['telephone'] ?? null,
                'personne_id' => $qui['personne_id'] ?? null,
                'patient_id' => $qui['patient_id'] ?? null,
                'ticket_precedent_id' => $qui['ticket_precedent_id'] ?? null,
                'visite_id' => $visite->id,
                'emis_at' => now(),
            ]);
        });
    }

    /**
     * Appelle le suivant dans ces services, vers ce point (salle, guichet).
     * L'urgence passe toujours ; sinon les prioritaires passent avant les
     * ordinaires, mais après `ratio_prioritaires` appels prioritaires de
     * suite, un ordinaire passe pour que personne n'attende indéfiniment.
     *
     * @param  list<int>  $serviceIds
     */
    public function appelerSuivant(array $serviceIds, ?PointService $point, ?User $par): ?Ticket
    {
        if ($serviceIds === []) {
            return null;
        }
        $premier = Service::findOrFail($serviceIds[0]);
        $jour = $this->aujourdhui($premier->etablissement()->firstOrFail());

        $ticket = DB::transaction(function () use ($serviceIds, $point, $par, $premier, $jour): ?Ticket {
            /** @var Collection<int, Ticket> $attente */
            $attente = Ticket::whereIn('service_id', $serviceIds)
                ->where('jour', $jour)
                ->where('statut', Statut::Emis->value)
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (Ticket $t): string => $t->priorite->rang().'-'.$t->emis_at->format('YmdHisu').'-'.str_pad((string) $t->id, 10, '0', STR_PAD_LEFT))
                ->values();

            if ($attente->isEmpty()) {
                return null;
            }

            $choisi = $attente->first();
            if ($choisi->priorite !== Priorite::Urgence) {
                $ordinaire = $attente->first(fn (Ticket $t): bool => $t->priorite === Priorite::Ordinaire);
                if ($ordinaire !== null && $choisi->priorite !== Priorite::Ordinaire
                    && $this->prioritairesDeSuite($serviceIds, $jour, $premier->ratio_prioritaires)) {
                    $choisi = $ordinaire;
                }
            }

            $choisi->update([
                'statut' => Statut::Appele,
                'point_service_id' => $point?->id,
                'appele_par' => $par?->id,
                'appele_at' => now(),
                'appels' => $choisi->appels + 1,
            ]);

            return $choisi;
        });

        if ($ticket !== null) {
            $this->prevenir($ticket);
        }

        return $ticket;
    }

    /** Rappelle un ticket (absent, ou à répéter sur l'écran). */
    public function rappeler(Ticket $ticket, ?PointService $point, ?User $par): Ticket
    {
        $ticket->update([
            'statut' => Statut::Appele,
            'point_service_id' => $point !== null ? $point->id : $ticket->point_service_id,
            'appele_par' => $par !== null ? $par->id : $ticket->appele_par,
            'appele_at' => now(),
            'appels' => $ticket->appels + 1,
        ]);
        $this->prevenir($ticket);

        return $ticket;
    }

    public function changer(Ticket $ticket, Statut $statut): Ticket
    {
        $ticket->update(array_filter([
            'statut' => $statut,
            'servi_at' => $statut === Statut::EnService ? now() : null,
            'termine_at' => in_array($statut, [Statut::Termine, Statut::Annule, Statut::Transfere], true) ? now() : null,
        ], static fn ($v): bool => $v !== null));

        return $ticket;
    }

    /**
     * Le patient passe dans un autre service (consultation → caisse) sans
     * refaire la queue depuis le début : il garde sa priorité, et le nouveau
     * ticket est lié au précédent.
     */
    public function transferer(Ticket $ticket, Service $vers): Ticket
    {
        $this->changer($ticket, Statut::Transfere);

        return $this->emettre($vers, $ticket->priorite, $ticket->canal, [
            'nom' => $ticket->nom,
            'telephone' => $ticket->telephone,
            'personne_id' => $ticket->personne_id,
            'patient_id' => $ticket->patient_id,
            'ticket_precedent_id' => $ticket->id,
        ]);
    }

    /** Personnes devant ce ticket dans son service (0 = c'est le prochain). */
    public function position(Ticket $ticket): ?int
    {
        if ($ticket->statut !== Statut::Emis) {
            return null;
        }
        $rang = $ticket->priorite->rang();

        return Ticket::where('service_id', $ticket->service_id)
            ->where('jour', $ticket->jour->toDateString())
            ->where('statut', Statut::Emis->value)
            ->where('id', '!=', $ticket->id)
            ->get(['priorite', 'emis_at', 'id'])
            ->filter(fn (Ticket $t): bool => $t->priorite->rang() < $rang
                || ($t->priorite->rang() === $rang && ($t->emis_at < $ticket->emis_at
                    || ($t->emis_at == $ticket->emis_at && $t->id < $ticket->id))))
            ->count();
    }

    /** Attente estimée en minutes, d'après le rythme du jour du service. */
    public function attenteMinutes(Ticket $ticket): ?int
    {
        $position = $this->position($ticket);
        if ($position === null) {
            return null;
        }

        return (int) ceil(($position + 1) * $this->dureeParPatient($ticket->service));
    }

    /**
     * Minutes par patient : moyenne des 10 derniers passages du jour, divisée
     * par le nombre de salles qui appellent ; la durée réglée sinon.
     */
    public function dureeParPatient(Service $service): float
    {
        $jour = $this->aujourdhui($service->etablissement()->firstOrFail());
        $derniers = Ticket::where('service_id', $service->id)
            ->where('jour', $jour)
            ->whereNotNull('appele_at')
            ->whereNotNull('termine_at')
            ->orderByDesc('termine_at')
            ->limit(10)
            ->get(['appele_at', 'termine_at']);

        $moyenne = $derniers->isEmpty()
            ? (float) $service->duree_moyenne_min
            : $derniers->avg(fn (Ticket $t): float => max(1.0, ($t->termine_at?->diffInSeconds($t->appele_at, true) ?? 0) / 60));

        $salles = Ticket::where('service_id', $service->id)
            ->where('jour', $jour)
            ->whereNotNull('point_service_id')
            ->distinct()
            ->count('point_service_id');

        return max(1.0, (float) $moyenne / max(1, $salles));
    }

    /**
     * Ce qu'affiche l'écran de la salle : par service, les derniers appelés
     * et le nombre d'attente ; et les appels récents pour les annonces.
     *
     * @param  list<string>|null  $servicesUuid  null = tous les services
     * @return array<string, mixed>
     */
    public function etat(Etablissement $etablissement, ?array $servicesUuid = null): array
    {
        $jour = $this->aujourdhui($etablissement);
        $services = Service::where('etablissement_id', $etablissement->id)
            ->where('actif', true)
            ->when($servicesUuid !== null, fn (Builder $q) => $q->whereIn('uuid', $servicesUuid ?? []))
            ->orderBy('ordre')
            ->orderBy('nom')
            ->get();

        $tickets = Ticket::with('point')
            ->whereIn('service_id', $services->pluck('id'))
            ->where('jour', $jour)
            ->get();

        $parService = $services->map(fn (Service $s): array => [
            'uuid' => $s->uuid,
            'nom' => $s->nom,
            'prefixe' => $s->prefixe,
            'en_attente' => $tickets->where('service_id', $s->id)->where('statut', Statut::Emis)->count(),
            'appeles' => $tickets->where('service_id', $s->id)
                ->filter(fn (Ticket $t): bool => in_array($t->statut, [Statut::Appele, Statut::EnService], true))
                ->sortByDesc('appele_at')
                ->take(4)
                ->map(fn (Ticket $t): array => $this->resumeAppel($t))
                ->values(),
        ])->values();

        return [
            'etablissement' => ['uuid' => $etablissement->uuid, 'nom' => $etablissement->nom],
            'jour' => $jour,
            'services' => $parService,
            'derniers_appels' => $tickets
                ->filter(fn (Ticket $t): bool => $t->appele_at !== null && $t->statut === Statut::Appele)
                ->sortByDesc('appele_at')
                ->take(6)
                ->map(fn (Ticket $t): array => $this->resumeAppel($t))
                ->values(),
            'horodatage' => now()->toIso8601String(),
        ];
    }

    /**
     * Les chiffres d'une journée : par service, combien de passages, d'absents,
     * l'attente moyenne et maximale, la durée de passage ; et l'affluence par
     * heure, pour placer le personnel aux heures chargées.
     *
     * @return array<string, mixed>
     */
    public function statistiques(Etablissement $etablissement, string $jour): array
    {
        $tickets = Ticket::with('service')
            ->where('etablissement_id', $etablissement->id)
            ->where('jour', $jour)
            ->get();

        $minutes = static fn (?Carbon $de, ?Carbon $a): ?float => $de !== null && $a !== null
            ? round($de->diffInSeconds($a, true) / 60, 1)
            : null;

        $parService = $tickets->groupBy('service_id')->map(function (Collection $lot) use ($minutes): array {
            /** @var Collection<int, Ticket> $lot */
            $attentes = $lot->map(fn (Ticket $t): ?float => $minutes($t->emis_at, $t->appele_at))->filter(fn ($v) => $v !== null);
            $passages = $lot->map(fn (Ticket $t): ?float => $minutes($t->appele_at, $t->termine_at))->filter(fn ($v) => $v !== null);
            $premier = $lot->first();

            return [
                'service' => $premier?->service->nom,
                'tickets' => $lot->count(),
                'servis' => $lot->where('statut', Statut::Termine)->count(),
                'absents' => $lot->where('statut', Statut::Absent)->count(),
                'annules' => $lot->where('statut', Statut::Annule)->count(),
                'en_attente' => $lot->where('statut', Statut::Emis)->count(),
                'attente_moyenne_min' => $attentes->isEmpty() ? null : round((float) $attentes->avg(), 1),
                'attente_max_min' => $attentes->isEmpty() ? null : $attentes->max(),
                'passage_moyen_min' => $passages->isEmpty() ? null : round((float) $passages->avg(), 1),
            ];
        })->values();

        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');
        $parHeure = $tickets
            ->groupBy(fn (Ticket $t): int => (int) $t->emis_at->copy()->setTimezone(is_string($fuseau) ? $fuseau : 'Africa/Bamako')->format('G'))
            ->map(fn (Collection $lot): int => $lot->count())
            ->sortKeys();

        return [
            'jour' => $jour,
            'total' => $tickets->count(),
            'services' => $parService,
            'par_heure' => $parHeure->map(fn (int $n, int $h): array => ['heure' => $h, 'tickets' => $n])->values(),
        ];
    }

    /**
     * Ticket pour l'équipe (avec le nom du patient).
     *
     * @return array<string, mixed>
     */
    public function pourEquipe(Ticket $ticket): array
    {
        return [
            ...$this->pourPublic($ticket),
            'nom' => $ticket->nom,
            'telephone' => $ticket->telephone,
            'canal' => $ticket->canal,
            'visite_uuid' => $ticket->visite?->uuid,
            'appels' => $ticket->appels,
        ];
    }

    /**
     * Ticket pour le suivi public (QR, app du patient) : jamais de nom.
     *
     * @return array<string, mixed>
     */
    public function pourPublic(Ticket $ticket): array
    {
        $ticket->loadMissing('service', 'point', 'etablissement');

        return [
            'uuid' => $ticket->uuid,
            'code' => $ticket->code,
            'priorite' => $ticket->priorite->value,
            'priorite_label' => $ticket->priorite->label(),
            'statut' => $ticket->statut->value,
            'statut_label' => $ticket->statut->label(),
            'service' => ['uuid' => $ticket->service->uuid, 'nom' => $ticket->service->nom],
            'point' => $ticket->point?->nom,
            'etablissement' => ['uuid' => $ticket->etablissement->uuid, 'nom' => $ticket->etablissement->nom],
            'position' => $this->position($ticket),
            'attente_min' => $this->attenteMinutes($ticket),
            'emis_at' => $ticket->emis_at->toIso8601String(),
            'appele_at' => $ticket->appele_at?->toIso8601String(),
            // Ce que contient le QR du ticket : une page lisible sans l'app.
            'suivi_url' => route('ticket.suivi', $ticket->uuid),
        ];
    }

    /**
     * @return array{uuid: string, code: string, service: string, point: string|null, appele_at: string|null, appels: int}
     */
    private function resumeAppel(Ticket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'code' => $ticket->code,
            'service' => $ticket->service->nom,
            'point' => $ticket->point?->nom,
            'appele_at' => $ticket->appele_at?->toIso8601String(),
            'appels' => $ticket->appels,
        ];
    }

    /**
     * Les derniers appels du jour dans ces services étaient-ils tous
     * prioritaires ?
     *
     * @param  list<int>  $serviceIds
     */
    private function prioritairesDeSuite(array $serviceIds, string $jour, int $ratio): bool
    {
        if ($ratio <= 0) {
            return false;
        }
        $derniers = Ticket::whereIn('service_id', $serviceIds)
            ->where('jour', $jour)
            ->whereNotNull('appele_at')
            ->orderByDesc('appele_at')
            ->orderByDesc('id')
            ->limit($ratio)
            ->pluck('priorite');

        return $derniers->count() === $ratio
            && $derniers->every(fn ($p): bool => $p !== Priorite::Ordinaire);
    }

    /**
     * SMS « c'est à vous », et « bientôt votre tour » aux suivants.
     */
    private function prevenir(Ticket $appele): void
    {
        $appele->loadMissing('service', 'point', 'etablissement');
        if ($appele->telephone !== null) {
            $ou = $appele->point !== null ? ' → '.$appele->point->nom : '';
            $this->sms->envoyer($appele->telephone, "Ngoni Santé : c'est à vous ! Ticket {$appele->code}{$ou}, {$appele->etablissement->nom}.");
        }

        $suivants = Ticket::where('service_id', $appele->service_id)
            ->where('jour', $appele->jour->toDateString())
            ->where('statut', Statut::Emis->value)
            ->where('prevenu_bientot', false)
            ->whereNotNull('telephone')
            ->get();

        foreach ($suivants as $ticket) {
            $position = $this->position($ticket);
            if ($position !== null && $position < self::BIENTOT) {
                $this->sms->envoyer((string) $ticket->telephone, "Ngoni Santé : bientôt votre tour (ticket {$ticket->code}). Revenez vers {$appele->service->nom}.");
                $ticket->update(['prevenu_bientot' => true]);
            }
        }
    }
}
