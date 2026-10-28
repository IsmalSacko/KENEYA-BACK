<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\PointService;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\Ticket;
use App\Sante\Dossier\Dossiers;
use App\Sante\File\FileAttente;
use App\Sante\File\Priorite;
use App\Sante\File\ServicesParDefaut;
use App\Sante\File\Statut;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La file d'attente côté équipe : services et salles, tickets, appels.
 *
 * @tag File d'attente
 */
class FileController extends Controller
{
    public function __construct(private readonly FileAttente $file, private readonly Dossiers $dossiers) {}

    // ------------------------------------------------------------ services

    public function services(): JsonResponse
    {
        $etablissement = $this->etablissement();
        ServicesParDefaut::creerSiAucun($etablissement);
        $jour = $this->file->aujourdhui($etablissement);

        $services = Service::with(['points' => fn ($q) => $q->orderBy('nom')])
            ->where('etablissement_id', $etablissement->id)
            ->orderBy('ordre')
            ->orderBy('nom')
            ->get()
            ->map(fn (Service $s): array => [
                ...$s->toArray(),
                'tarif_uuid' => $s->tarif_id !== null ? Tarif::whereKey($s->tarif_id)->value('uuid') : null,
                'en_attente' => Ticket::where('service_id', $s->id)->where('jour', $jour)
                    ->where('statut', Statut::Emis->value)->count(),
            ]);

        return response()->json(['data' => $services]);
    }

    public function creerService(Request $request): JsonResponse
    {
        $donnees = $this->validerService($request, true);
        $service = Service::create([...$donnees, 'etablissement_id' => $this->etablissement()->id]);

        return response()->json(['service' => $service], 201);
    }

    public function modifierService(Request $request, string $uuid): JsonResponse
    {
        $service = $this->service($uuid);
        $service->update($this->validerService($request, false, $service));

        return response()->json(['service' => $service->fresh()]);
    }

    public function supprimerService(string $uuid): JsonResponse
    {
        $service = $this->service($uuid);
        // Des patients attendent encore : ils disparaîtraient de la file.
        $enCours = Ticket::where('service_id', $service->id)
            ->where('jour', $this->file->aujourdhui($this->etablissement()))
            ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value])
            ->count();
        if ($enCours > 0) {
            throw new Refus("{$enCours} patient(s) sont encore dans la file de {$service->nom} : servez-les ou envoyez-les ailleurs, ou fermez le service.");
        }
        $service->delete();

        return response()->json(['message' => 'Service supprimé.']);
    }

    public function creerPoint(Request $request, string $uuid): JsonResponse
    {
        $service = $this->service($uuid);
        $donnees = $request->validate(['nom' => 'required|string|max:60']);
        $point = PointService::create([
            'etablissement_id' => $service->etablissement_id,
            'service_id' => $service->id,
            'nom' => $donnees['nom'],
        ]);

        return response()->json(['point' => $point], 201);
    }

    public function modifierPoint(Request $request, string $uuid): JsonResponse
    {
        $point = $this->point($uuid);
        $point->update($request->validate([
            'nom' => 'sometimes|string|max:60',
            'actif' => 'sometimes|boolean',
        ]));

        return response()->json(['point' => $point->fresh()]);
    }

    public function supprimerPoint(string $uuid): JsonResponse
    {
        $this->point($uuid)->delete();

        return response()->json(['message' => 'Salle supprimée.']);
    }

    // ------------------------------------------------------------ tickets

    /** Les tickets du jour (encore en cours, puis les derniers terminés). */
    public function tickets(Request $request): JsonResponse
    {
        $etablissement = $this->etablissement();
        $requete = Ticket::with('service', 'point', 'etablissement')
            ->where('etablissement_id', $etablissement->id)
            ->where('jour', $this->file->aujourdhui($etablissement));

        $service = $request->query('service');
        if (is_string($service)) {
            $requete->where('service_id', $this->service($service)->id);
        }

        $tickets = $requete->orderBy('emis_at')->get()
            ->sortBy(fn (Ticket $t): string => ($t->statut->enCours() ? '0' : '1').$t->priorite->rang().$t->emis_at->format('His'))
            ->values()
            ->map(fn (Ticket $t): array => $this->file->pourEquipe($t));

        return response()->json(['data' => $tickets]);
    }

    public function emettre(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'service_uuid' => 'required|uuid',
            'priorite' => ['sometimes', Rule::in(Priorite::values())],
            'nom' => 'sometimes|nullable|string|max:120',
            'telephone' => 'sometimes|nullable|string|max:20',
            'dossier_uuid' => 'sometimes|nullable|uuid',
        ]);
        $service = $this->service($donnees['service_uuid']);
        if (! $service->actif) {
            return response()->json(['message' => 'Ce service est fermé.'], 422);
        }

        // Le patient choisi à l'accueil : la visite s'ouvre dans son dossier.
        $qui = ['nom' => $donnees['nom'] ?? null, 'telephone' => $donnees['telephone'] ?? null];
        if (is_string($donnees['dossier_uuid'] ?? null)) {
            $dossier = $this->dossierPatient($donnees['dossier_uuid'], $service->etablissement_id);
            $qui = ['nom' => $dossier->nom, 'telephone' => $dossier->telephone, 'dossier_id' => $dossier->id, 'personne_id' => $dossier->personne_id];
        }

        $ticket = $this->file->emettre(
            $service,
            Priorite::from($donnees['priorite'] ?? Priorite::Ordinaire->value),
            'guichet',
            $qui,
        );

        return response()->json(['ticket' => $this->file->pourEquipe($ticket)], 201);
    }

    /** « Appeler le suivant » depuis une salle ou un guichet. */
    public function appeler(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'services' => 'required|array|min:1',
            'services.*' => 'uuid',
            'point_uuid' => 'sometimes|nullable|uuid',
        ]);
        $ids = array_values(array_map(fn (string $u): int => $this->service($u)->id, $donnees['services']));
        $point = isset($donnees['point_uuid']) ? $this->point($donnees['point_uuid']) : null;

        $ticket = $this->file->appelerSuivant($ids, $point, $this->currentUser());
        if ($ticket === null) {
            return response()->json(['message' => 'Personne en attente.', 'ticket' => null]);
        }

        return response()->json(['ticket' => $this->file->pourEquipe($ticket)]);
    }

    public function action(Request $request, string $uuid, string $action): JsonResponse
    {
        $ticket = $this->ticket($uuid);
        // Un ticket clos ne revient pas : un autre poste l'a peut-être déjà
        // terminé ou envoyé ailleurs pendant qu'on regardait la liste.
        if (! $ticket->statut->enCours()) {
            throw new Refus("Le ticket {$ticket->code} est déjà ".mb_strtolower($ticket->statut->label()).'.');
        }

        $ticket = match ($action) {
            'rappeler' => $this->file->rappeler(
                $ticket,
                is_string($request->input('point_uuid')) ? $this->point($request->string('point_uuid')->toString()) : null,
                $this->currentUser(),
            ),
            'servir' => $this->file->changer($ticket, Statut::EnService),
            'terminer' => $this->file->changer($ticket, Statut::Termine),
            'absent' => $this->file->changer($ticket, Statut::Absent),
            'annuler' => $this->file->changer($ticket, Statut::Annule),
            'transferer' => $this->file->transferer(
                $ticket,
                $this->service($request->validate(['service_uuid' => 'required|uuid'])['service_uuid']),
            ),
            default => abort(404),
        };

        return response()->json(['ticket' => $this->file->pourEquipe($ticket)]);
    }

    /** L'accueil corrige la priorité d'un patient qui attend (urgence, femme enceinte…). */
    public function priorite(Request $request, string $uuid): JsonResponse
    {
        $ticket = $this->ticket($uuid);
        $priorite = $request->validate(['priorite' => ['required', Rule::in(Priorite::values())]])['priorite'];
        if ($ticket->statut !== Statut::Emis) {
            throw new Refus('Seul un ticket en attente change de priorité.');
        }
        $ticket->update(['priorite' => Priorite::from($priorite)]);

        return response()->json(['ticket' => $this->file->pourEquipe($ticket)]);
    }

    public function statistiques(Request $request): JsonResponse
    {
        $etablissement = $this->etablissement();
        $jour = $request->query('jour');

        return response()->json($this->file->statistiques(
            $etablissement,
            is_string($jour) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) === 1 ? $jour : $this->file->aujourdhui($etablissement),
        ));
    }

    public function etat(): JsonResponse
    {
        return response()->json($this->file->etat($this->etablissement()));
    }

    // ------------------------------------------------------------ interne

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }

    private function service(string $uuid): Service
    {
        return Service::where('etablissement_id', $this->currentUser()->etablissement_id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function point(string $uuid): PointService
    {
        return PointService::where('etablissement_id', $this->currentUser()->etablissement_id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function ticket(string $uuid): Ticket
    {
        return Ticket::where('etablissement_id', $this->currentUser()->etablissement_id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validerService(Request $request, bool $creation, ?Service $service = null): array
    {
        $requis = $creation ? 'required' : 'sometimes';
        $prefixeUnique = Rule::unique('services', 'prefixe')
            ->where('etablissement_id', $this->currentUser()->etablissement_id)
            ->whereNull('deleted_at');
        if ($service !== null) {
            $prefixeUnique->ignore($service->id);
        }

        /** @var array<string, mixed> $donnees */
        $donnees = $request->validate([
            'nom' => "{$requis}|string|max:60",
            'prefixe' => [$requis, 'string', 'max:3', 'regex:/^[A-Z]+$/', $prefixeUnique],
            'duree_moyenne_min' => 'sometimes|integer|min:1|max:240',
            'ratio_prioritaires' => 'sometimes|integer|min:0|max:10',
            'ticket_distance' => 'sometimes|boolean',
            'actif' => 'sometimes|boolean',
            'ordre' => 'sometimes|integer|min:0|max:999',
            'tarif_uuid' => 'sometimes|nullable|uuid',
        ]);
        // L'acte facturé à chaque ticket : un tarif de l'établissement, ou aucun.
        if (array_key_exists('tarif_uuid', $donnees)) {
            $donnees['tarif_id'] = is_string($donnees['tarif_uuid'])
                ? Tarif::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $donnees['tarif_uuid'])->firstOrFail()->id
                : null;
            unset($donnees['tarif_uuid']);
        }

        return $donnees;
    }

    /** Un patient connu ici, ou qui a un compte Ngoni Santé. */
    private function dossierPatient(string $uuid, int $etablissementId): Dossier
    {
        $dossier = Dossier::where('uuid', $uuid)->firstOrFail();
        abort_unless(
            $dossier->personne_id !== null || $this->dossiers->connu($dossier, Etablissement::findOrFail($etablissementId)),
            404,
        );

        return $dossier;
    }
}
