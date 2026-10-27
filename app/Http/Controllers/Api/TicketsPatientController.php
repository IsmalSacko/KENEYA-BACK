<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Service;
use App\Models\Ticket;
use App\Sante\Dossier\Dossiers;
use App\Sante\File\FileAttente;
use App\Sante\File\Priorite;
use App\Sante\File\Statut;
use App\Sante\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La file côté patient : voir l'attente d'un établissement, prendre un
 * ticket à distance, suivre ses tickets. Le suivi par QR est public (sans
 * nom).
 *
 * @tag File d'attente
 */
class TicketsPatientController extends Controller
{
    public function __construct(private readonly FileAttente $file) {}

    /** Services où l'on peut prendre un ticket, avec l'attente du moment. */
    public function fileEtablissement(string $uuid): JsonResponse
    {
        $etablissement = $this->etablissementAvecFile($uuid);
        $jour = $this->file->aujourdhui($etablissement);

        $services = Service::where('etablissement_id', $etablissement->id)
            ->where('actif', true)
            ->orderBy('ordre')
            ->orderBy('nom')
            ->get()
            ->map(function (Service $s) use ($jour): array {
                $attente = Ticket::where('service_id', $s->id)->where('jour', $jour)
                    ->where('statut', Statut::Emis->value)->count();

                return [
                    'uuid' => $s->uuid,
                    'nom' => $s->nom,
                    'ticket_distance' => $s->ticket_distance,
                    'en_attente' => $attente,
                    'attente_min' => (int) ceil($attente * $this->file->dureeParPatient($s)),
                ];
            });

        return response()->json([
            'etablissement' => ['uuid' => $etablissement->uuid, 'nom' => $etablissement->nom],
            'services' => $services,
        ]);
    }

    /** Suivi public d'un ticket (QR imprimé) : jamais de nom. */
    public function suivi(string $uuid): JsonResponse
    {
        return response()->json(['ticket' => $this->file->pourPublic(Ticket::where('uuid', $uuid)->firstOrFail())]);
    }

    /** La personne prend un ticket depuis son téléphone. */
    public function prendre(Request $request): JsonResponse
    {
        $personne = $this->currentPersonne();
        $donnees = $request->validate(['service_uuid' => 'required|uuid']);
        $service = Service::where('uuid', $donnees['service_uuid'])->where('actif', true)->firstOrFail();
        $etablissement = $this->etablissementAvecFile($service->etablissement()->firstOrFail()->uuid);

        if (! $service->ticket_distance) {
            return response()->json(['message' => 'Ce service ne donne pas de ticket à distance.'], 422);
        }

        // Un seul ticket en cours par service : reprendre le même.
        $existant = Ticket::where('service_id', $service->id)
            ->where('jour', $this->file->aujourdhui($etablissement))
            ->where('personne_id', $personne->id)
            ->whereIn('statut', [Statut::Emis->value, Statut::Appele->value, Statut::EnService->value])
            ->first();
        if ($existant !== null) {
            return response()->json(['ticket' => $this->file->pourPublic($existant)]);
        }

        $ticket = $this->file->emettre($service, Priorite::Ordinaire, 'app', [
            'nom' => $personne->nom,
            'telephone' => $personne->telephone,
            'personne_id' => $personne->id,
            'dossier_id' => app(Dossiers::class)->pourPersonne($personne)->id,
        ]);

        return response()->json(['ticket' => $this->file->pourPublic($ticket)], 201);
    }

    /** Les tickets de la personne aujourd'hui, en cours d'abord. */
    public function mesTickets(): JsonResponse
    {
        $tickets = Ticket::with('service', 'point', 'etablissement')
            ->where('personne_id', $this->currentPersonne()->id)
            ->where('emis_at', '>=', now()->subDay())
            ->orderByDesc('emis_at')
            ->get()
            ->sortBy(fn (Ticket $t): int => $t->statut->enCours() ? 0 : 1)
            ->values()
            ->map(fn (Ticket $t): array => $this->file->pourPublic($t));

        return response()->json(['data' => $tickets]);
    }

    public function annuler(string $uuid): JsonResponse
    {
        $ticket = Ticket::where('uuid', $uuid)->where('personne_id', $this->currentPersonne()->id)->firstOrFail();
        if ($ticket->statut === Statut::Emis) {
            $this->file->changer($ticket, Statut::Annule);
        }

        return response()->json(['ticket' => $this->file->pourPublic($ticket)]);
    }

    private function etablissementAvecFile(string $uuid): Etablissement
    {
        $etablissement = Etablissement::visible()->where('uuid', $uuid)->firstOrFail();
        if (! in_array(Module::File->value, $etablissement->modules, true)) {
            abort(404, "Cet établissement n'a pas de file d'attente.");
        }

        return $etablissement;
    }
}
