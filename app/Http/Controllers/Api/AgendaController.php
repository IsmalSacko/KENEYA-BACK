<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agenda;
use App\Models\AgendaAbsence;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\RendezVous;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Sante\Dossier\Dossiers;
use App\Sante\File\FileAttente;
use App\Sante\File\Priorite;
use App\Sante\Qr;
use App\Sante\Rdv\Agendas;
use App\Sante\Refus;
use App\Sante\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rendez-vous côté équipe : agendas, créneaux, réservations au téléphone,
 * et l'accueil qui scanne un QR (rendez-vous ou carte santé).
 *
 * @tag Rendez-vous
 */
class AgendaController extends Controller
{
    private const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct(
        private readonly Agendas $agendas,
        private readonly FileAttente $file,
        private readonly Dossiers $dossiers,
    ) {}

    // ------------------------------------------------------------ agendas

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Agenda::with('horaires', 'absences', 'service', 'membre')
                ->where('etablissement_id', $this->etablissementId())
                ->orderBy('nom')
                ->get()
                ->map(fn (Agenda $a): array => $this->agendaPourEquipe($a)),
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $agenda = Agenda::create([...$this->validerAgenda($request, true), 'etablissement_id' => $this->etablissementId()]);
        $this->enregistrerHoraires($agenda, $request);

        return response()->json(['agenda' => $this->agendaPourEquipe($agenda->fresh() ?? $agenda)], 201);
    }

    public function modifier(Request $request, string $uuid): JsonResponse
    {
        $agenda = $this->agenda($uuid);
        $agenda->update($this->validerAgenda($request, false));
        $this->enregistrerHoraires($agenda, $request);

        return response()->json(['agenda' => $this->agendaPourEquipe($agenda->fresh() ?? $agenda)]);
    }

    public function supprimer(string $uuid): JsonResponse
    {
        $agenda = $this->agenda($uuid);
        // Des patients comptent sur ces rendez-vous : on ne les efface pas en silence.
        $aVenir = RendezVous::where('agenda_id', $agenda->id)->whereIn('statut', ['demande', 'confirme'])
            ->where(fn ($q) => $q->where('debut', '>=', now())->orWhere(fn ($q2) => $q2->whereNull('debut')->where('date_souhaitee', '>=', now()->startOfDay())))
            ->count();
        if ($aVenir > 0) {
            throw new Refus("{$aVenir} rendez-vous à venir dans cet agenda : déplacez-les ou annulez-les d’abord, ou mettez l’agenda en pause.");
        }
        $agenda->delete();

        return response()->json(['message' => 'Agenda supprimé.']);
    }

    public function ajouterAbsence(Request $request, string $uuid): JsonResponse
    {
        $agenda = $this->agenda($uuid);
        $donnees = $request->validate([
            'du' => 'required|date',
            'au' => 'required|date|after_or_equal:du',
            'motif' => 'sometimes|nullable|string|max:120',
        ]);
        $absence = AgendaAbsence::create([...$donnees, 'agenda_id' => $agenda->id]);

        // Les rendez-vous déjà pris pendant l'absence : à déplacer ou annuler.
        $fuseau = $this->agendas->fuseau($agenda->etablissement);
        $touches = RendezVous::with('agenda', 'etablissement')->where('agenda_id', $agenda->id)
            ->whereIn('statut', ['demande', 'confirme'])
            ->whereBetween('debut', [
                Carbon::parse($donnees['du'], $fuseau)->startOfDay()->utc(),
                Carbon::parse($donnees['au'], $fuseau)->endOfDay()->utc(),
            ])
            ->orderBy('debut')->get();

        return response()->json([
            'absence' => $absence,
            'rendez_vous' => $touches->map(fn (RendezVous $r): array => $this->agendas->pourAffichage($r, true)),
        ], 201);
    }

    public function supprimerAbsence(int $id): JsonResponse
    {
        AgendaAbsence::whereKey($id)
            ->whereIn('agenda_id', Agenda::where('etablissement_id', $this->etablissementId())->pluck('id'))
            ->delete();

        return response()->json(['message' => 'Absence supprimée.']);
    }

    public function creneaux(Request $request, string $uuid): JsonResponse
    {
        [$du, $au] = $this->periode($request);

        return response()->json(['data' => $this->agendas->creneaux($this->agenda($uuid), $du, $au, false)]);
    }

    // ------------------------------------------------------------ rendez-vous

    public function rendezVous(Request $request): JsonResponse
    {
        [$du, $au] = $this->periode($request);
        $etablissement = Etablissement::findOrFail($this->etablissementId());
        $fuseau = $this->agendas->fuseau($etablissement);
        $debut = Carbon::parse($du, $fuseau)->startOfDay()->utc();
        $fin = Carbon::parse($au, $fuseau)->endOfDay()->utc();

        $requete = RendezVous::with('agenda', 'etablissement')
            ->where('etablissement_id', $etablissement->id)
            ->where(fn ($q) => $q->whereBetween('debut', [$debut, $fin])
                ->orWhere(fn ($q2) => $q2->whereNull('debut')->whereBetween('date_souhaitee', [$debut, $fin])));

        $agenda = $request->query('agenda');
        if (is_string($agenda)) {
            $requete->where('agenda_id', $this->agenda($agenda)->id);
        }
        // Retrouver le rendez-vous d'un patient : par son nom ou son numéro,
        // du mois passé aux mois à venir, sans connaître le jour.
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $requete = RendezVous::with('agenda', 'etablissement')
                ->where('etablissement_id', $etablissement->id)
                ->where(fn ($w) => $w->where('debut', '>=', now()->subDays(30))
                    ->orWhere(fn ($w2) => $w2->whereNull('debut')->where('date_souhaitee', '>=', now()->subDays(30))))
                ->when(is_string($agenda), fn ($w) => $w->where('agenda_id', $this->agenda(is_string($agenda) ? $agenda : '')->id));
            foreach (preg_split('/\s+/', trim($q)) ?: [] as $mot) {
                $requete->where(fn ($w) => $w->where('patient_nom', 'like', '%'.$mot.'%')->orWhere('patient_telephone', 'like', '%'.$mot.'%'));
            }
            $requete->limit(50);
        }

        return response()->json([
            'data' => $requete->orderByRaw('coalesce(debut, date_souhaitee)')->get()
                ->map(fn (RendezVous $r): array => $this->agendas->pourAffichage($r, true)),
        ]);
    }

    /** L'accueil prend un rendez-vous au téléphone. */
    public function reserver(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'agenda_uuid' => 'required|uuid',
            'debut' => 'required|date',
            'dossier_uuid' => 'sometimes|nullable|uuid',
            'nom' => 'required_without:dossier_uuid|nullable|string|max:120',
            'telephone' => 'sometimes|nullable|string|max:20',
            'motif' => 'sometimes|nullable|string|max:255',
        ]);
        $agenda = $this->agenda($donnees['agenda_uuid']);
        $etab = Etablissement::findOrFail($agenda->etablissement_id);

        // Le rendez-vous est celui d'un patient : choisi, ou retrouvé/créé
        // avec son nom et son numéro.
        if (is_string($donnees['dossier_uuid'] ?? null)) {
            $dossier = Dossier::where('uuid', $donnees['dossier_uuid'])->firstOrFail();
            abort_unless($dossier->personne_id !== null || $this->dossiers->connu($dossier, $etab), 404);
        } else {
            $pays = Pays::find($etab->pays_code);
            $tel = is_string($donnees['telephone'] ?? null) && trim($donnees['telephone']) !== '' ? trim($donnees['telephone']) : null;
            $dossier = $this->dossiers->trouverOuCreer($etab, [
                'nom' => (string) $donnees['nom'],
                'telephone' => $tel !== null && $pays !== null ? Telephone::normaliser($tel, $pays) : $tel,
            ]);
        }

        try {
            $rdv = $this->agendas->reserver(
                $agenda,
                Carbon::parse($donnees['debut']),
                [
                    'nom' => $dossier->nom,
                    'telephone' => $dossier->telephone,
                    'personne_id' => $dossier->personne_id,
                    'dossier_id' => $dossier->id,
                    'motif' => $donnees['motif'] ?? null,
                    'canal' => 'accueil',
                ],
                false,
            );
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['rendez_vous' => $this->agendas->pourAffichage($rdv, true)], 201);
    }

    public function action(Request $request, string $uuid, string $action): JsonResponse
    {
        $rdv = $this->rdv($uuid);
        $donnees = $request->validate([
            'raison' => 'sometimes|nullable|string|max:255',
            // Une demande venue de l'app sans heure : l'accueil la fixe en confirmant.
            'debut' => 'sometimes|nullable|date',
            'agenda_uuid' => 'sometimes|nullable|uuid',
        ]);
        $statut = match ($action) {
            'confirmer' => 'confirme',
            'refuser' => 'refuse',
            'annuler' => 'annule',
            'absent' => 'absent',
            default => abort(404),
        };
        if (! in_array($rdv->statut, ['demande', 'confirme'], true)) {
            throw new Refus('Ce rendez-vous est déjà '.$this->agendas->libelleStatut($rdv->statut).'.');
        }
        if ($action === 'confirmer' && is_string($donnees['debut'] ?? null)) {
            $agenda = is_string($donnees['agenda_uuid'] ?? null) ? $this->agenda($donnees['agenda_uuid']) : $rdv->agenda;
            if ($agenda === null) {
                throw new Refus('Choisissez l’agenda de ce rendez-vous.');
            }
            $this->agendas->deplacer($rdv, $agenda, Carbon::parse($donnees['debut']));
        }
        $rdv->update(['statut' => $statut]);
        $this->agendas->prevenir($rdv, $statut, $donnees['raison'] ?? null);

        return response()->json(['rendez_vous' => $this->agendas->pourAffichage($rdv, true)]);
    }

    /** L'accueil note l'arrivée du patient (le jour du rendez-vous). */
    public function arriver(string $uuid): JsonResponse
    {
        return $this->arrivee($this->rdv($uuid), 'guichet');
    }

    /** Autre heure, autre jour, ou autre agenda : le patient est prévenu. */
    public function deplacer(Request $request, string $uuid): JsonResponse
    {
        $rdv = $this->rdv($uuid);
        $donnees = $request->validate([
            'debut' => 'required|date',
            'agenda_uuid' => 'sometimes|nullable|uuid',
            'raison' => 'sometimes|nullable|string|max:255',
        ]);
        $agenda = is_string($donnees['agenda_uuid'] ?? null) ? $this->agenda($donnees['agenda_uuid']) : $rdv->agenda;
        if ($agenda === null) {
            throw new Refus('Choisissez l’agenda de ce rendez-vous.');
        }
        $avant = $rdv->debut?->copy();
        $this->agendas->deplacer($rdv, $agenda, Carbon::parse($donnees['debut']));
        if ($avant === null || ! $avant->equalTo($rdv->debut)) {
            $this->agendas->prevenir($rdv, 'deplace', $donnees['raison'] ?? null);
        }

        return response()->json(['rendez_vous' => $this->agendas->pourAffichage($rdv, true)]);
    }

    /**
     * L'accueil scanne un QR : un rendez-vous (arrivée → ticket), ou la carte
     * santé (le patient est identifié ; ticket si un service est donné).
     */
    public function scanner(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'qr' => 'required|string|max:200',
            'service_uuid' => 'sometimes|nullable|uuid',
            'priorite' => ['sometimes', Rule::in(Priorite::values())],
        ]);
        $lu = Qr::lire($donnees['qr']);

        if ($lu !== null && $lu[0] === Qr::RDV) {
            $rdv = RendezVous::where('etablissement_id', $this->etablissementId())->where('uuid', $lu[1])->first();
            if ($rdv === null) {
                return response()->json(['message' => 'Ce rendez-vous n’est pas dans cet établissement.'], 404);
            }

            return $this->arrivee($rdv, 'guichet');
        }

        // Le ticket du patient (son QR ouvre la page de suivi) : on le montre.
        if (preg_match('#/t/([0-9a-fA-F-]{36})#', $donnees['qr'], $m) === 1) {
            $ticket = Ticket::where('uuid', $m[1])->first();
            if ($ticket === null || $ticket->etablissement_id !== $this->etablissementId()) {
                return response()->json(['message' => 'Ce ticket est celui d’un autre établissement.'], 404);
            }

            return response()->json(['type' => 'ticket', 'ticket' => $this->file->pourEquipe($ticket)]);
        }

        if ($lu !== null && $lu[0] === 'ord') {
            return response()->json(['message' => 'C’est une ordonnance : elle se scanne à la Pharmacie.'], 422);
        }
        if ($lu !== null && $lu[0] === Qr::ETABLISSEMENT) {
            return response()->json(['message' => 'C’est l’affiche de l’accueil : le patient la scanne avec son téléphone pour signaler son arrivée.'], 422);
        }

        // La carte santé, ou le QR « Mon dossier » : le patient est identifié.
        if ($lu !== null && ($lu[0] === Qr::CARTE || $lu[0] === 'accord')) {
            $personne = $lu[0] === Qr::CARTE
                ? Personne::where('carte_jeton', $lu[1])->first()
                : Personne::where('accord_jeton', $lu[1])->where('accord_expire_at', '>', now())->first();
            if ($personne === null) {
                return response()->json(['message' => 'Carte inconnue ou renouvelée.'], 404);
            }
            $reponse = ['type' => 'carte', 'personne' => ['nom' => $personne->nom, 'telephone' => $personne->telephone]];

            if (isset($donnees['service_uuid'])) {
                $service = Service::where('etablissement_id', $this->etablissementId())
                    ->where('uuid', $donnees['service_uuid'])->firstOrFail();
                $ticket = $this->file->emettre(
                    $service,
                    Priorite::from($donnees['priorite'] ?? Priorite::Ordinaire->value),
                    'guichet',
                    ['nom' => $personne->nom, 'telephone' => $personne->telephone, 'personne_id' => $personne->id],
                );
                $reponse['ticket'] = $this->file->pourEquipe($ticket);
            }

            return response()->json($reponse);
        }

        return response()->json(['message' => 'QR non reconnu. Ici, on scanne le QR d’un rendez-vous, la carte santé (app Ngoni Santé › Ma carte) ou un ticket.'], 422);
    }

    /** Le QR à afficher à l'accueil : le patient le scanne pour signaler son arrivée. */
    public function qrAccueil(): JsonResponse
    {
        $etablissement = Etablissement::findOrFail($this->etablissementId());

        return response()->json([
            'qr' => Qr::creer(Qr::ETABLISSEMENT, $etablissement->uuid),
            'etablissement' => $etablissement->nom,
        ]);
    }

    // ------------------------------------------------------------ interne

    private function arrivee(RendezVous $rdv, string $canal): JsonResponse
    {
        try {
            $ticket = $this->agendas->arrivee($rdv, $canal);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'type' => 'rdv',
            'rendez_vous' => $this->agendas->pourAffichage($rdv->fresh() ?? $rdv, true),
            'ticket' => $this->file->pourEquipe($ticket),
        ]);
    }

    private function etablissementId(): int
    {
        return (int) $this->currentUser()->etablissement_id;
    }

    private function rdv(string $uuid): RendezVous
    {
        return RendezVous::with('agenda.etablissement', 'agenda.horaires', 'agenda.absences', 'etablissement')
            ->where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();
    }

    private function agenda(string $uuid): Agenda
    {
        return Agenda::with('horaires', 'absences', 'etablissement')
            ->where('etablissement_id', $this->etablissementId())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periode(Request $request): array
    {
        $du = $request->query('du');
        $au = $request->query('au');
        $date = '/^\d{4}-\d{2}-\d{2}$/';
        $du = is_string($du) && preg_match($date, $du) === 1 ? $du : now()->toDateString();
        $au = is_string($au) && preg_match($date, $au) === 1 ? $au : $du;

        return [$du, $au];
    }

    /**
     * @return array<string, mixed>
     */
    private function validerAgenda(Request $request, bool $creation): array
    {
        $requis = $creation ? 'required' : 'sometimes';
        /** @var array<string, mixed> $donnees */
        $donnees = $request->validate([
            'nom' => "{$requis}|string|max:80",
            'service_uuid' => 'sometimes|nullable|uuid',
            'membre_uuid' => 'sometimes|nullable|uuid',
            'duree_creneau_min' => 'sometimes|integer|min:5|max:240',
            'places_par_creneau' => 'sometimes|integer|min:1|max:50',
            'reservation_en_ligne' => 'sometimes|boolean',
            'validation_auto' => 'sometimes|boolean',
            'delai_min_heures' => 'sometimes|integer|min:0|max:168',
            'horizon_jours' => 'sometimes|integer|min:1|max:365',
            'actif' => 'sometimes|boolean',
            'horaires' => 'sometimes|array',
            'horaires.*.jour' => 'required|integer|between:1,7',
            'horaires.*.debut' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'horaires.*.fin' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        // Une plage qui finit avant de commencer serait perdue sans le dire.
        $horaires = is_array($donnees['horaires'] ?? null) ? $donnees['horaires'] : [];
        foreach ($horaires as $i => $h) {
            if (is_array($h) && is_string($h['debut'] ?? null) && is_string($h['fin'] ?? null) && $h['fin'] <= $h['debut']) {
                $jour = is_numeric($h['jour'] ?? null) ? mb_strtolower(self::JOURS[(int) $h['jour'] - 1] ?? '') : '';
                throw ValidationException::withMessages([
                    'horaires.'.$i.'.fin' => "Les heures du {$jour} : la fin ({$h['fin']}) doit venir après le début ({$h['debut']}).",
                ]);
            }
        }
        if (array_key_exists('service_uuid', $donnees)) {
            $donnees['service_id'] = is_string($donnees['service_uuid'])
                ? Service::where('etablissement_id', $this->etablissementId())->where('uuid', $donnees['service_uuid'])->firstOrFail()->id
                : null;
        }
        if (array_key_exists('membre_uuid', $donnees)) {
            $donnees['membre_id'] = is_string($donnees['membre_uuid'])
                ? User::where('etablissement_id', $this->etablissementId())->where('uuid', $donnees['membre_uuid'])->firstOrFail()->id
                : null;
        }
        unset($donnees['service_uuid'], $donnees['membre_uuid'], $donnees['horaires']);

        return $donnees;
    }

    private function enregistrerHoraires(Agenda $agenda, Request $request): void
    {
        $horaires = $request->input('horaires');
        if (! is_array($horaires)) {
            return;
        }
        $agenda->horaires()->delete();
        foreach ($horaires as $h) {
            if (is_array($h) && ($h['fin'] ?? '') > ($h['debut'] ?? '')) {
                $agenda->horaires()->create(['jour' => $h['jour'], 'debut' => $h['debut'], 'fin' => $h['fin']]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function agendaPourEquipe(Agenda $agenda): array
    {
        $agenda->loadMissing('horaires', 'absences', 'service', 'membre');

        return [
            ...$agenda->toArray(),
            'service' => $agenda->service !== null ? ['uuid' => $agenda->service->uuid, 'nom' => $agenda->service->nom] : null,
            'membre' => $agenda->membre !== null ? ['uuid' => $agenda->membre->uuid, 'nom' => $agenda->membre->getAttribute('name')] : null,
        ];
    }
}
