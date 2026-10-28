<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\User;
use App\Sante\Abonnements\Abonnements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * L'abonnement vu par l'établissement : où il en est, les formules, payer et
 * envoyer sa demande, l'historique. Réservé à ses administrateurs, même
 * quand l'abonnement est échu (il faut pouvoir renouveler).
 */
class AbonnementController extends Controller
{
    public function __construct(private readonly Abonnements $abonnements) {}

    public function etat(Request $request): JsonResponse
    {
        $etab = $this->etablissement($request);

        return response()->json([
            'abonnement' => $this->abonnements->etat($etab),
            'formules' => $this->abonnements->catalogue(),
            'paiement' => [
                'numeros' => $this->numerosPaiement(),
                'whatsapp' => config('sante.abonnement.whatsapp') ?: null,
                'moyens' => DemandeAbonnement::MOYENS,
            ],
            'historique' => DemandeAbonnement::where('etablissement_id', $etab->id)->latest()->limit(20)->get()
                ->map(fn (DemandeAbonnement $d): array => $this->abonnements->demande($d))->values(),
        ]);
    }

    public function demander(Request $request): JsonResponse
    {
        $etab = $this->etablissement($request, admin: true);
        $d = $request->validate([
            'formule' => 'required|string|max:20',
            'mois' => 'required|integer|in:1,3,6,12',
            'moyen' => ['required', Rule::in(DemandeAbonnement::MOYENS)],
            'telephone' => 'nullable|string|max:30',
            'note' => 'nullable|string|max:255',
            'preuve' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);
        $chemin = $request->file('preuve')?->store("abonnements/{$etab->uuid}");
        $personne = $request->user();

        $demande = $this->abonnements->demander($etab, $personne instanceof User ? $personne->personne_id : null, [
            'formule' => (string) $d['formule'],
            'mois' => (int) $d['mois'],
            'moyen' => (string) $d['moyen'],
            'telephone' => isset($d['telephone']) ? (string) $d['telephone'] : null,
            'note' => isset($d['note']) ? (string) $d['note'] : null,
            'preuve_chemin' => is_string($chemin) ? $chemin : null,
        ]);

        return response()->json(['demande' => $this->abonnements->demande($demande)], 201);
    }

    public function annuler(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement($request, admin: true);
        $demande = DemandeAbonnement::where('uuid', $uuid)->where('etablissement_id', $etab->id)
            ->where('statut', 'en_attente')->firstOrFail();
        $demande->update(['statut' => 'annulee']);

        return response()->json(['demande' => $this->abonnements->demande($demande)]);
    }

    private function etablissement(Request $request, bool $admin = false): Etablissement
    {
        $membre = $request->user();
        abort_unless($membre instanceof User, 401);
        abort_if($admin && ! in_array($membre->role, Role::etablissementAdmins(), true), 403, 'Seul un administrateur gère l’abonnement.');
        $id = $membre->getAttribute('etablissement_id');

        return Etablissement::findOrFail(is_numeric($id) ? (int) $id : 0);
    }

    /**
     * @return list<array{telephone: string, moyens: list<string>}>
     */
    private function numerosPaiement(): array
    {
        $brut = config('sante.abonnement.numeros_paiement');
        $numeros = [];
        foreach (explode(';', is_string($brut) ? $brut : '') as $morceau) {
            [$tel, $moyens] = array_pad(explode(':', trim($morceau), 2), 2, '');
            if ($tel !== '') {
                $numeros[] = ['telephone' => $tel, 'moyens' => array_values(array_filter(explode(',', $moyens)))];
            }
        }

        return $numeros;
    }
}
