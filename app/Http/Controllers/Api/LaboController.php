<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\DemandeExamen;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use App\Sante\Labo\Labo;
use App\Sante\Qr;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le laboratoire : catalogue, demandes, prélèvement, résultats.
 *
 * @tag Laboratoire
 */
class LaboController extends Controller
{
    public function __construct(private readonly Labo $labo, private readonly Dossiers $dossiers) {}

    public function analyses(Request $request): JsonResponse
    {
        $toutes = $request->boolean('toutes');

        return response()->json([
            'data' => $this->labo->catalogue($this->etablissement(), $toutes)->map(fn (Analyse $a): array => $this->analyse($a)),
        ]);
    }

    public function creerAnalyse(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $this->labo->catalogue($etab); // le catalogue de départ d'abord
        $donnees = $request->validate([
            ...$this->reglesAnalyse(),
            'code' => ['required', 'string', 'max:20', Rule::unique('analyses')->where('etablissement_id', $etab->id)],
            'nom' => 'required|string|max:255',
            'type_resultat' => 'required|in:nombre,choix,texte',
        ]);

        $donnees['categorie'] ??= 'autre';

        return response()->json(['analyse' => $this->analyse($this->labo->enregistrer($etab, $donnees))], 201);
    }

    public function modifierAnalyse(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $analyse = Analyse::where('etablissement_id', $etab->id)->where('uuid', $uuid)->firstOrFail();
        $donnees = $request->validate([...$this->reglesAnalyse(), 'nom' => 'sometimes|string|max:255', 'actif' => 'sometimes|boolean']);

        return response()->json(['analyse' => $this->analyse($this->labo->enregistrer($etab, $donnees, $analyse))]);
    }

    /** La liste du labo : à prélever, à saisir ou valider, et validées du jour. */
    public function demandes(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $statut = $request->query('statut');
        $requete = DemandeExamen::where('etablissement_id', $etab->id);
        if ($statut === 'validee') {
            $requete->where('statut', 'validee')->where('valide_at', '>=', now()->subDay())->latest('valide_at');
        } else {
            $requete->whereIn('statut', ['demandee', 'prelevee'])->orderByDesc('urgent')->orderBy('created_at');
        }

        return response()->json([
            'data' => $requete->limit(200)->get()->map(fn (DemandeExamen $d): array => $this->labo->pourAffichage($d)),
        ]);
    }

    public function demander(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $donnees = $request->validate([
            'visite_uuid' => 'required_without:dossier_uuid|nullable|string',
            'dossier_uuid' => 'required_without:visite_uuid|nullable|string',
            'analyses' => 'required_without:autres|array',
            'analyses.*' => 'string',
            'autres' => 'sometimes|array|max:10',
            'autres.*' => 'nullable|string|max:120',
            'urgent' => 'sometimes|boolean',
            'renseignements' => 'sometimes|nullable|string|max:1000',
        ]);

        $visite = null;
        if (is_string($donnees['visite_uuid'] ?? null)) {
            $visite = Visite::where('etablissement_id', $etab->id)->where('uuid', $donnees['visite_uuid'])->firstOrFail();
            $dossier = $this->dossiers->pourVisite($visite);
        } else {
            $dossier = Dossier::where('uuid', $donnees['dossier_uuid'])->firstOrFail();
            abort_unless($this->dossiers->connu($dossier, $etab), 404);
        }
        $analyses = Analyse::with('tarif')->where('etablissement_id', $etab->id)->where('actif', true)
            ->whereIn('uuid', $donnees['analyses'] ?? [])->get();
        $autres = array_values(array_filter($donnees['autres'] ?? [], 'is_string'));

        try {
            $demande = $this->labo->demander(
                $etab, $dossier, $visite, $analyses, $this->currentUser(),
                (bool) ($donnees['urgent'] ?? false), $donnees['renseignements'] ?? null, $autres,
            );
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['demande' => $this->labo->pourAffichage($demande)], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['demande' => $this->labo->pourAffichage($this->demande($uuid))]);
    }

    /** L'étiquette du tube, scannée au labo. */
    public function lire(Request $request): JsonResponse
    {
        $lu = Qr::lire($request->validate(['qr' => 'required|string|max:200'])['qr']);
        if ($lu === null || $lu[0] !== Labo::QR) {
            return response()->json(['message' => 'Ce QR n’est pas une étiquette du laboratoire.'], 422);
        }

        return $this->show($lu[1]);
    }

    public function prelever(string $uuid): JsonResponse
    {
        return $this->faire(fn () => $this->labo->prelever($this->demande($uuid), $this->currentUser()));
    }

    public function saisir(Request $request, string $uuid): JsonResponse
    {
        /** @var list<array{uuid: string, resultat?: string|null, commentaire?: string|null}> $resultats */
        $resultats = $request->validate([
            'resultats' => 'required|array',
            'resultats.*.uuid' => 'required|string',
            'resultats.*.resultat' => 'nullable|string|max:2000',
            'resultats.*.commentaire' => 'nullable|string|max:500',
        ])['resultats'];

        return $this->faire(fn () => $this->labo->saisir($this->demande($uuid), $resultats, $this->currentUser()));
    }

    public function valider(string $uuid): JsonResponse
    {
        return $this->faire(fn () => $this->labo->valider($this->demande($uuid), $this->currentUser()));
    }

    public function annuler(string $uuid): JsonResponse
    {
        return $this->faire(fn () => $this->labo->annuler($this->demande($uuid)));
    }

    // ------------------------------------------------------------ interne

    /**
     * @param  callable(): DemandeExamen  $action
     */
    private function faire(callable $action): JsonResponse
    {
        try {
            $demande = $action();
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['demande' => $this->labo->pourAffichage($demande->refresh())]);
    }

    private function demande(string $uuid): DemandeExamen
    {
        return DemandeExamen::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(Analyse $a): array
    {
        return [
            'uuid' => $a->uuid,
            'code' => $a->code,
            'nom' => $a->nom,
            'categorie' => $a->categorie,
            'type_resultat' => $a->type_resultat,
            'unite' => $a->unite,
            'normale_min' => $a->normale_min,
            'normale_max' => $a->normale_max,
            'normale' => $a->normale(),
            'choix' => $a->choix ?? [],
            'choix_normal' => $a->choix_normal,
            'prix' => $a->tarif?->prix,
            'actif' => $a->actif,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesAnalyse(): array
    {
        return [
            'categorie' => 'sometimes|string|max:30',
            'type_resultat' => 'sometimes|in:nombre,choix,texte',
            'unite' => 'sometimes|nullable|string|max:20',
            'normale_min' => 'sometimes|nullable|numeric',
            'normale_max' => 'sometimes|nullable|numeric|gte:normale_min',
            'choix' => 'sometimes|nullable|array',
            'choix.*' => 'string|max:40',
            'choix_normal' => 'sometimes|nullable|string|max:40',
            'prix' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
