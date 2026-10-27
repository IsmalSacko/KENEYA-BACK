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

/**
 * Rendez-vous côté équipe : agendas, créneaux, réservations au téléphone,
 * et l'accueil qui scanne un QR (rendez-vous ou carte santé).
 *
 * @tag Rendez-vous
 */
class AgendaController extends Controller
{
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
        $this->agenda($uuid)->delete();

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

        return response()->json(['absence' => $absence], 201);
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

    public function action(string $uuid, string $action): JsonResponse
    {
        $rdv = RendezVous::where('etablissement_id', $this->etablissementId())->where('uuid', $uuid)->firstOrFail();

        if ($action === 'arrivee') {
            return $this->arrivee($rdv, 'guichet');
        }

        $rdv->update(['statut' => match ($action) {
            'confirmer' => 'confirme',
            'refuser' => 'refuse',
            'annuler' => 'annule',
            'absent' => 'absent',
            default => abort(404),
        }]);

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

        if ($lu !== null && $lu[0] === Qr::CARTE) {
            $personne = Personne::where('carte_jeton', $lu[1])->first();
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

        return response()->json(['message' => 'Ce QR n’est pas un rendez-vous ni une carte santé.'], 422);
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
