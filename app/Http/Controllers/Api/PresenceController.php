<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use App\Models\Hospitalisation;
use App\Models\User;
use App\Models\Visiteur;
use App\Sante\Badges\Presence;
use App\Sante\Qr;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Badges du personnel (pointage) et visiteurs.
 *
 * @tag Badges
 */
class PresenceController extends Controller
{
    public function __construct(private readonly Presence $presence) {}

    /** Les badges des membres, à imprimer. */
    public function badges(): JsonResponse
    {
        $etab = $this->etablissement('badges');
        $membres = User::where('etablissement_id', $etab->id)->where('actif', true)->orderBy('name')->get();

        return response()->json(['data' => $membres->map(fn (User $m): array => $this->membre($m))]);
    }

    public function renouveler(string $uuid): JsonResponse
    {
        $etab = $this->etablissement('badges');
        $membre = User::where('etablissement_id', $etab->id)->where('uuid', $uuid)->firstOrFail();
        $this->presence->renouveler($membre);

        return response()->json(['membre' => $this->membre($membre)]);
    }

    public function pointer(Request $request): JsonResponse
    {
        $qr = $request->validate(['qr' => 'required|string|max:200'])['qr'];
        try {
            $p = $this->presence->pointer($this->etablissement('badges'), $qr, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['pointage' => [
            'nom' => $p->membre->name,
            'type' => $p->type,
            'at' => $p->at->toIso8601String(),
        ]]);
    }

    public function presents(): JsonResponse
    {
        return response()->json(['data' => $this->presence->presents($this->etablissement('badges'))]);
    }

    public function rapport(Request $request): JsonResponse
    {
        $mois = $request->validate(['mois' => 'sometimes|date_format:Y-m'])['mois'] ?? now()->format('Y-m');

        return response()->json(['mois' => $mois, 'data' => $this->presence->rapport($this->etablissement('badges'), $mois)]);
    }

    // ------------------------------------------------------------ visiteurs

    public function visiteurs(): JsonResponse
    {
        $etab = $this->etablissement('hospitalisation');
        $presents = Visiteur::where('etablissement_id', $etab->id)->whereNull('sortie_at')->orderBy('entree_at')->get();

        return response()->json(['data' => $presents->map(fn (Visiteur $v): array => $this->presence->visiteur($v))]);
    }

    /** Les patients qu'on peut visiter : nom et lit, rien de médical. */
    public function patients(): JsonResponse
    {
        $etab = $this->etablissement('hospitalisation');
        $sejours = Hospitalisation::with('dossier', 'lit.chambre')->where('etablissement_id', $etab->id)
            ->where('statut', 'en_cours')->get()->sortBy(fn (Hospitalisation $h): string => $h->dossier->nom);

        return response()->json(['data' => $sejours->values()->map(fn (Hospitalisation $h): array => [
            'uuid' => $h->uuid,
            'patient' => $h->dossier->nom,
            'lit' => $h->lit?->libelle(),
            'visiteurs' => Visiteur::where('hospitalisation_id', $h->id)->whereNull('sortie_at')->count(),
        ])]);
    }

    public function entrer(Request $request): JsonResponse
    {
        $etab = $this->etablissement('hospitalisation');
        $donnees = $request->validate([
            'nom' => 'required|string|max:120',
            'telephone' => 'sometimes|nullable|string|max:20',
            'piece' => 'sometimes|nullable|string|max:40',
            'sejour_uuid' => 'sometimes|nullable|string',
        ]);
        $sejour = is_string($donnees['sejour_uuid'] ?? null)
            ? Hospitalisation::where('etablissement_id', $etab->id)->where('uuid', $donnees['sejour_uuid'])->firstOrFail()
            : null;
        try {
            $v = $this->presence->entrer($etab, [
                'nom' => (string) $donnees['nom'],
                'telephone' => $donnees['telephone'] ?? null,
                'piece' => $donnees['piece'] ?? null,
            ], $sejour, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['visiteur' => $this->presence->visiteur($v)], 201);
    }

    /** Le badge visiteur scanné à la sortie (ou le bouton « Sorti »). */
    public function sortir(Request $request): JsonResponse
    {
        $etab = $this->etablissement('hospitalisation');
        $donnees = $request->validate(['qr' => 'required_without:uuid|nullable|string|max:200', 'uuid' => 'required_without:qr|nullable|string']);
        $uuid = $donnees['uuid'] ?? null;
        if (is_string($donnees['qr'] ?? null)) {
            $lu = Qr::lire($donnees['qr']);
            if ($lu === null || $lu[0] !== Presence::QR_VISITEUR) {
                return response()->json(['message' => 'Ce QR n’est pas un badge visiteur.'], 422);
            }
            $uuid = $lu[1];
        }
        $v = Visiteur::where('etablissement_id', $etab->id)->where('uuid', $uuid)->firstOrFail();

        return response()->json(['visiteur' => $this->presence->visiteur($this->presence->sortir($v))]);
    }

    // ------------------------------------------------------------ interne

    /** L'établissement du membre ; 403 si le module n'est pas activé. */
    private function etablissement(string $module): Etablissement
    {
        $etab = Etablissement::findOrFail($this->currentUser()->etablissement_id);
        abort_unless(in_array($module, $etab->modules, true), 403, 'Module non activé pour cet établissement.');

        return $etab;
    }

    /**
     * @return array<string, mixed>
     */
    private function membre(User $m): array
    {
        return [
            'uuid' => $m->uuid,
            'nom' => $m->name,
            'role' => Role::tryFrom($m->role)?->label() ?? '',
            'badge' => $this->presence->badge($m),
        ];
    }
}
