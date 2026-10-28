<?php

declare(strict_types=1);

namespace App\Sante\Rdv;

use App\Models\Agenda;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\RendezVous;
use App\Models\Service;
use App\Models\Ticket;
use App\Sante\File\FileAttente;
use App\Sante\File\Priorite;
use App\Sante\File\ServicesParDefaut;
use App\Sante\Qr;
use App\Sante\Refus;
use App\Sante\Sms\EnvoiSms;
use App\Sante\Telephone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Créneaux libres d'un agenda, réservation, arrivée du patient.
 *
 * Les heures sont pensées dans le fuseau du pays de l'établissement (un
 * cabinet de Dakar ouvre à 8 h heure de Dakar) et stockées en UTC.
 */
final class Agendas
{
    public function __construct(
        private readonly FileAttente $file,
        private readonly EnvoiSms $sms,
    ) {}

    public function fuseau(Etablissement $etablissement): string
    {
        $fuseau = Pays::whereKey($etablissement->pays_code)->value('fuseau');

        return is_string($fuseau) ? $fuseau : 'Africa/Bamako';
    }

    /**
     * Les créneaux entre deux jours (inclus), avec les places restantes.
     * Passés, trop proches (délai minimal) ou en absence : exclus.
     *
     * @return list<array{debut: string, fin: string, heure: string, places: int}>
     */
    public function creneaux(Agenda $agenda, string $du, string $au, bool $pourPatient = true): array
    {
        $fuseau = $this->fuseau($agenda->etablissement);
        $jour = Carbon::parse($du, $fuseau)->startOfDay();
        $dernier = Carbon::parse($au, $fuseau)->startOfDay();
        $limite = Carbon::now($fuseau)->addDays($agenda->horizon_jours)->endOfDay();
        if ($dernier->gt($limite)) {
            $dernier = $limite->copy()->startOfDay();
        }
        $auPlusTot = $pourPatient ? Carbon::now()->addHours($agenda->delai_min_heures) : Carbon::now();

        $pris = RendezVous::where('agenda_id', $agenda->id)
            ->whereIn('statut', RendezVous::OCCUPE)
            ->whereBetween('debut', [$jour->copy()->utc(), $dernier->copy()->endOfDay()->utc()])
            ->get(['debut'])
            ->countBy(fn (RendezVous $r): string => $r->debut?->utc()->format('Y-m-d H:i') ?? '');

        $creneaux = [];
        for (; $jour->lte($dernier); $jour->addDay()) {
            if ($this->enAbsence($agenda, $jour->toDateString())) {
                continue;
            }
            foreach ($agenda->horaires->where('jour', $jour->isoWeekday()) as $plage) {
                $debut = $jour->copy()->setTimeFromTimeString($plage->debut);
                $finPlage = $jour->copy()->setTimeFromTimeString($plage->fin);
                for (; $debut->copy()->addMinutes($agenda->duree_creneau_min)->lte($finPlage); $debut->addMinutes($agenda->duree_creneau_min)) {
                    $utc = $debut->copy()->utc();
                    if ($utc->lt($auPlusTot)) {
                        continue;
                    }
                    $places = $agenda->places_par_creneau - ($pris[$utc->format('Y-m-d H:i')] ?? 0);
                    if ($places <= 0) {
                        continue;
                    }
                    $creneaux[] = [
                        'debut' => $utc->toIso8601String(),
                        'fin' => $utc->copy()->addMinutes($agenda->duree_creneau_min)->toIso8601String(),
                        'heure' => $debut->format('H:i'),
                        'jour' => $debut->toDateString(),
                        'places' => $places,
                    ];
                }
            }
        }

        return $creneaux;
    }

    /**
     * Réserve un créneau. Le verrou sur l'agenda évite de dépasser les
     * places quand deux patients réservent en même temps.
     *
     * @param  array{nom: string, telephone: string|null, personne_id?: int|null, dossier_id?: int|null, motif?: string|null, canal: string}  $qui
     */
    public function reserver(Agenda $agenda, Carbon $debut, array $qui, bool $pourPatient = true): RendezVous
    {
        $debut = $debut->copy()->utc()->seconds(0);

        return DB::transaction(function () use ($agenda, $debut, $qui, $pourPatient): RendezVous {
            Agenda::whereKey($agenda->id)->lockForUpdate()->first();

            $fuseau = $this->fuseau($agenda->etablissement);
            $local = $debut->copy()->setTimezone($fuseau);
            $libre = collect($this->creneaux($agenda, $local->toDateString(), $local->toDateString(), $pourPatient))
                ->contains(fn (array $c): bool => Carbon::parse($c['debut'])->equalTo($debut));
            if (! $libre) {
                throw new Refus('Ce créneau n’est plus libre.');
            }

            $pays = Pays::find($agenda->etablissement->pays_code);
            $telephone = $qui['telephone'] === null || trim($qui['telephone']) === ''
                ? ''
                : ($pays !== null ? Telephone::normaliser($qui['telephone'], $pays) : $qui['telephone']);

            return RendezVous::create([
                'etablissement_id' => $agenda->etablissement_id,
                'agenda_id' => $agenda->id,
                'medecin_id' => $agenda->membre_id,
                'personne_id' => $qui['personne_id'] ?? null,
                'dossier_id' => $qui['dossier_id'] ?? null,
                'patient_nom' => $qui['nom'],
                'patient_telephone' => $telephone,
                'date_souhaitee' => $debut,
                'debut' => $debut,
                'fin' => $debut->copy()->addMinutes($agenda->duree_creneau_min),
                'motif' => $qui['motif'] ?? null,
                'statut' => $agenda->validation_auto || ! $pourPatient ? 'confirme' : 'demande',
                'canal' => $qui['canal'],
            ]);
        });
    }

    /**
     * Déplace un rendez-vous (autre heure, autre jour, autre agenda), si le
     * nouveau créneau est libre. Les rappels repartent pour la nouvelle date.
     */
    public function deplacer(RendezVous $rdv, Agenda $agenda, Carbon $debut): RendezVous
    {
        if (! in_array($rdv->statut, ['demande', 'confirme'], true)) {
            throw new Refus('Ce rendez-vous est '.$this->libelleStatut($rdv->statut).' : il ne se déplace plus.');
        }
        $debut = $debut->copy()->utc()->seconds(0);
        if ($rdv->agenda_id === $agenda->id && $rdv->debut !== null && $rdv->debut->equalTo($debut)) {
            return $rdv;
        }

        DB::transaction(function () use ($rdv, $agenda, $debut): void {
            Agenda::whereKey($agenda->id)->lockForUpdate()->first();
            $local = $debut->copy()->setTimezone($this->fuseau($agenda->etablissement));
            $libre = collect($this->creneaux($agenda, $local->toDateString(), $local->toDateString(), false))
                ->contains(fn (array $c): bool => Carbon::parse($c['debut'])->equalTo($debut));
            if (! $libre) {
                throw new Refus('Ce créneau n’est plus libre.');
            }
            $rdv->update([
                'agenda_id' => $agenda->id,
                'medecin_id' => $agenda->membre_id,
                'debut' => $debut,
                'fin' => $debut->copy()->addMinutes($agenda->duree_creneau_min),
                'rappel_veille_at' => null,
                'rappel_proche_at' => null,
            ]);
        });

        return $rdv->refresh();
    }

    /**
     * Le patient apprend par SMS ce que l'établissement a décidé : confirmé,
     * refusé, annulé, déplacé (avec la raison donnée, s'il y en a une).
     */
    public function prevenir(RendezVous $rdv, string $quoi, ?string $raison = null): void
    {
        if ($rdv->patient_telephone === '') {
            return;
        }
        $rdv->loadMissing('etablissement');
        $local = ($rdv->debut ?? $rdv->date_souhaitee)->copy()->setTimezone($this->fuseau($rdv->etablissement));
        $quand = $rdv->debut !== null ? "du {$local->format('d/m')} à {$local->format('H:i')}" : "du {$local->format('d/m')}";
        $pourquoi = $raison !== null && trim($raison) !== '' ? ' ('.trim($raison).')' : '';
        $ou = $rdv->etablissement->nom;

        $message = match ($quoi) {
            'confirme' => "Ngoni Santé : votre rendez-vous {$quand} à {$ou} est confirmé.",
            'refuse' => "Ngoni Santé : votre demande de rendez-vous {$quand} à {$ou} n'a pas pu être acceptée{$pourquoi}. Choisissez une autre date dans l'app.",
            'annule' => "Ngoni Santé : votre rendez-vous {$quand} à {$ou} est annulé{$pourquoi}. Reprenez rendez-vous dans l'app.",
            'deplace' => "Ngoni Santé : votre rendez-vous à {$ou} est déplacé au {$local->format('d/m')} à {$local->format('H:i')}{$pourquoi}.",
            default => null,
        };
        if ($message !== null) {
            $this->sms->envoyer($rdv->patient_telephone, $message);
        }
    }

    /**
     * Le patient est arrivé : il entre dans la file avec la priorité
     * « rendez-vous », dans le service de l'agenda. Seulement le jour même :
     * un ticket se donne pour aujourd'hui.
     */
    public function arrivee(RendezVous $rdv, string $canal): Ticket
    {
        if ($rdv->ticket_id !== null && ($existant = Ticket::find($rdv->ticket_id)) !== null) {
            return $existant;
        }
        if (! in_array($rdv->statut, ['demande', 'confirme'], true)) {
            throw new Refus('Ce rendez-vous est '.$this->libelleStatut($rdv->statut).'.');
        }
        $rdv->loadMissing('etablissement');
        $fuseau = $this->fuseau($rdv->etablissement);
        $jour = ($rdv->debut ?? $rdv->date_souhaitee)->copy()->setTimezone($fuseau);
        if ($jour->toDateString() !== Carbon::now($fuseau)->toDateString()) {
            throw new Refus("Ce rendez-vous est le {$jour->format('d/m')} : l’arrivée se note le jour même.");
        }

        $service = $this->serviceDe($rdv);
        $ticket = $this->file->emettre($service, Priorite::RendezVous, $canal, [
            'nom' => $rdv->patient_nom,
            'telephone' => $rdv->patient_telephone !== '' ? $rdv->patient_telephone : null,
            'personne_id' => $rdv->personne_id,
            'dossier_id' => $rdv->dossier_id,
        ]);
        $rdv->update(['arrivee_at' => now(), 'ticket_id' => $ticket->id, 'statut' => 'honore']);

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function pourAffichage(RendezVous $rdv, bool $avecPatient): array
    {
        $rdv->loadMissing('agenda', 'etablissement');
        $fuseau = $this->fuseau($rdv->etablissement);
        $quand = $rdv->debut ?? $rdv->date_souhaitee;

        return array_filter([
            'uuid' => $rdv->uuid,
            'statut' => $rdv->statut,
            'statut_label' => $this->libelleStatut($rdv->statut),
            'debut' => $quand->toIso8601String(),
            'jour' => $quand->copy()->setTimezone($fuseau)->toDateString(),
            'heure' => $quand->copy()->setTimezone($fuseau)->format('H:i'),
            'creneau' => $rdv->debut !== null,
            'agenda' => $rdv->agenda !== null ? ['uuid' => $rdv->agenda->uuid, 'nom' => $rdv->agenda->nom] : null,
            'etablissement' => ['uuid' => $rdv->etablissement->uuid, 'nom' => $rdv->etablissement->nom],
            'motif' => $rdv->motif,
            'canal' => $rdv->canal,
            'arrivee_at' => $rdv->arrivee_at?->toIso8601String(),
            'qr' => Qr::creer(Qr::RDV, $rdv->uuid),
            'patient_nom' => $avecPatient ? $rdv->patient_nom : null,
            'patient_telephone' => $avecPatient && $rdv->patient_telephone !== '' ? $rdv->patient_telephone : null,
            'dossier_uuid' => $avecPatient && $rdv->dossier_id !== null ? Dossier::whereKey($rdv->dossier_id)->value('uuid') : null,
        ], static fn ($v): bool => $v !== null);
    }

    public function libelleStatut(string $statut): string
    {
        return match ($statut) {
            'demande' => 'en attente de confirmation',
            'confirme' => 'confirmé',
            'refuse' => 'refusé',
            'annule' => 'annulé',
            'honore' => 'honoré',
            'absent' => 'manqué',
            default => $statut,
        };
    }

    private function enAbsence(Agenda $agenda, string $jour): bool
    {
        return $agenda->absences->contains(
            fn ($a): bool => $a->du->toDateString() <= $jour && $a->au->toDateString() >= $jour,
        );
    }

    /** Le service de la file où entre le patient : celui de l'agenda, sinon le premier. */
    private function serviceDe(RendezVous $rdv): Service
    {
        $service = $rdv->agenda?->service;
        if ($service !== null && $service->actif) {
            return $service;
        }
        ServicesParDefaut::creerSiAucun($rdv->etablissement);

        return Service::where('etablissement_id', $rdv->etablissement_id)
            ->where('actif', true)
            ->orderBy('ordre')
            ->firstOrFail();
    }
}
