<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agenda;
use App\Models\Appareil;
use App\Models\Etablissement;
use App\Models\RendezVous;
use App\Sante\Dossier\Dossiers;
use App\Sante\File\FileAttente;
use App\Sante\Module;
use App\Sante\Qr;
use App\Sante\Rdv\Agendas;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Rendez-vous côté patient : créneaux libres, réservation, rappels,
 * arrivée par QR. Et la carte santé QR.
 *
 * @tag Rendez-vous
 */
class RdvPatientController extends Controller
{
    public function __construct(
        private readonly Agendas $agendas,
        private readonly FileAttente $file,
        private readonly Dossiers $dossiers,
    ) {}

    /** Agendas où l'on peut réserver en ligne (public). */
    public function agendasEtablissement(string $uuid): JsonResponse
    {
        $etablissement = Etablissement::where('uuid', $uuid)->where('actif', true)->firstOrFail();
        if (! in_array(Module::RendezVous->value, $etablissement->modules, true)) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => Agenda::with('service')
                ->where('etablissement_id', $etablissement->id)
                ->where('actif', true)
                ->where('reservation_en_ligne', true)
                ->orderBy('nom')
                ->get()
                ->map(fn (Agenda $a): array => [
                    'uuid' => $a->uuid,
                    'nom' => $a->nom,
                    'service' => $a->service?->nom,
                    'duree_creneau_min' => $a->duree_creneau_min,
                    'validation_auto' => $a->validation_auto,
                ]),
        ]);
    }

    /** Créneaux libres d'un agenda (public). */
    public function creneaux(Request $request, string $uuid): JsonResponse
    {
        $agenda = $this->agendaEnLigne($uuid);
        $du = $request->query('du');
        $au = $request->query('au');
        $du = is_string($du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) === 1 ? $du : now()->toDateString();
        $au = is_string($au) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) === 1 ? $au : Carbon::parse($du)->addDays(6)->toDateString();

        return response()->json(['data' => $this->agendas->creneaux($agenda, $du, $au)]);
    }

    public function reserver(Request $request): JsonResponse
    {
        $personne = $this->currentPersonne();
        $donnees = $request->validate([
            'agenda_uuid' => 'required|uuid',
            'debut' => 'required|date',
            'motif' => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $rdv = $this->agendas->reserver($this->agendaEnLigne($donnees['agenda_uuid']), Carbon::parse($donnees['debut']), [
                'nom' => $personne->nom,
                'telephone' => $personne->telephone,
                'personne_id' => $personne->id,
                'dossier_id' => $this->dossiers->pourPersonne($personne)->id,
                'motif' => $donnees['motif'] ?? null,
                'canal' => 'app',
            ]);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['rendez_vous' => $this->agendas->pourAffichage($rdv, false)], 201);
    }

    /** Ses rendez-vous : à venir d'abord, puis les 30 derniers jours. */
    public function mesRdv(): JsonResponse
    {
        $personne = $this->currentPersonne();
        $rdv = RendezVous::with('agenda', 'etablissement')
            ->where(fn ($q) => $q->where('personne_id', $personne->id)
                // Demandes faites avant le compte, avec le même numéro.
                ->orWhere(fn ($q2) => $q2->whereNull('personne_id')->where('patient_telephone', $personne->telephone)))
            ->whereRaw('coalesce(debut, date_souhaitee) >= ?', [now()->subDays(30)])
            ->get()
            ->sortBy(fn (RendezVous $r): string => (($r->debut ?? $r->date_souhaitee)->isFuture() ? '0' : '1')
                .($r->debut ?? $r->date_souhaitee)->format('YmdHi'))
            ->values()
            ->map(fn (RendezVous $r): array => $this->agendas->pourAffichage($r, false));

        return response()->json(['data' => $rdv]);
    }

    public function annuler(string $uuid): JsonResponse
    {
        $rdv = $this->sonRdv($uuid);
        if (in_array($rdv->statut, ['demande', 'confirme'], true)) {
            $rdv->update(['statut' => 'annule']);
        }

        return response()->json(['rendez_vous' => $this->agendas->pourAffichage($rdv, false)]);
    }

    /**
     * Arrivée : la personne scanne l'affiche de l'accueil ; son rendez-vous
     * du jour dans cet établissement la fait entrer dans la file.
     */
    public function arrivee(Request $request): JsonResponse
    {
        $personne = $this->currentPersonne();
        $lu = Qr::lire($request->validate(['qr' => 'required|string|max:200'])['qr']);
        if ($lu === null || $lu[0] !== Qr::ETABLISSEMENT) {
            return response()->json(['message' => 'Scannez l’affiche Ngoni Santé de l’accueil.'], 422);
        }
        $etablissement = Etablissement::where('uuid', $lu[1])->firstOrFail();
        $fuseau = $this->agendas->fuseau($etablissement);

        $rdv = RendezVous::with('agenda', 'etablissement')
            ->where('etablissement_id', $etablissement->id)
            ->where(fn ($q) => $q->where('personne_id', $personne->id)->orWhere('patient_telephone', $personne->telephone))
            ->whereIn('statut', ['demande', 'confirme', 'honore'])
            ->whereBetween('debut', [Carbon::now($fuseau)->startOfDay()->utc(), Carbon::now($fuseau)->endOfDay()->utc()])
            ->orderBy('debut')
            ->first();

        if ($rdv === null) {
            return response()->json([
                'message' => 'Pas de rendez-vous aujourd’hui ici. Vous pouvez prendre un ticket.',
                'code' => 'pas_de_rdv',
                'etablissement' => ['uuid' => $etablissement->uuid, 'nom' => $etablissement->nom],
            ], 404);
        }

        try {
            $ticket = $this->agendas->arrivee($rdv, 'app');
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'rendez_vous' => $this->agendas->pourAffichage($rdv->fresh() ?? $rdv, false),
            'ticket' => $this->file->pourPublic($ticket),
        ]);
    }

    /** La carte santé QR de la personne. */
    public function carte(): JsonResponse
    {
        $personne = $this->currentPersonne();

        return response()->json([
            'qr' => Qr::creer(Qr::CARTE, (string) $personne->carte_jeton),
            'nom' => $personne->nom,
            'telephone' => $personne->telephone,
        ]);
    }

    /** Carte perdue : un nouveau jeton, l'ancien QR ne marche plus. */
    public function renouvelerCarte(): JsonResponse
    {
        $personne = $this->currentPersonne();
        $personne->update(['carte_jeton' => Str::random(32)]);

        return $this->carte();
    }

    /** La borne scanne le QR d'un rendez-vous : le patient entre dans la file. */
    public function scanBorne(Request $request): JsonResponse
    {
        $appareil = $request->attributes->get('appareil');
        if (! $appareil instanceof Appareil || $appareil->mode !== Appareil::BORNE) {
            return response()->json(['message' => 'Seule une borne accueille les rendez-vous.'], 403);
        }
        $lu = Qr::lire($request->validate(['qr' => 'required|string|max:200'])['qr']);
        if ($lu === null || $lu[0] !== Qr::RDV) {
            return response()->json(['message' => 'Montrez le QR de votre rendez-vous.'], 422);
        }
        $rdv = RendezVous::with('agenda', 'etablissement')
            ->where('etablissement_id', $appareil->etablissement_id)
            ->where('uuid', $lu[1])
            ->first();
        if ($rdv === null) {
            return response()->json(['message' => 'Ce rendez-vous n’est pas dans cet établissement.'], 404);
        }

        try {
            $ticket = $this->agendas->arrivee($rdv, 'borne');
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ticket' => $this->file->pourPublic($ticket)]);
    }

    private function agendaEnLigne(string $uuid): Agenda
    {
        return Agenda::with('horaires', 'absences', 'etablissement')
            ->where('uuid', $uuid)
            ->where('actif', true)
            ->where('reservation_en_ligne', true)
            ->firstOrFail();
    }

    private function sonRdv(string $uuid): RendezVous
    {
        $personne = $this->currentPersonne();

        return RendezVous::with('agenda', 'etablissement')
            ->where('uuid', $uuid)
            ->where(fn ($q) => $q->where('personne_id', $personne->id)->orWhere('patient_telephone', $personne->telephone))
            ->firstOrFail();
    }
}
