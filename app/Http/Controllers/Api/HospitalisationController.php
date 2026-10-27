<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chambre;
use App\Models\Etablissement;
use App\Models\Hospitalisation;
use App\Models\Lit;
use App\Models\SoinPrescrit;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use App\Sante\Hospitalisation\Hospitalisations;
use App\Sante\Qr;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * L'hospitalisation : chambres et lits, séjours, plan de soins, bracelet.
 *
 * @tag Hospitalisation
 */
class HospitalisationController extends Controller
{
    public function __construct(private readonly Hospitalisations $hospit, private readonly Dossiers $dossiers) {}

    /** Les chambres, leurs lits, qui y est couché. */
    public function chambres(): JsonResponse
    {
        $etab = $this->etablissement();
        $occupes = $this->hospit->occupation($etab);
        $chambres = Chambre::with('lits', 'tarif')->where('etablissement_id', $etab->id)->orderBy('nom')->get();

        return response()->json([
            'data' => $chambres->map(fn (Chambre $c): array => [
                'uuid' => $c->uuid,
                'nom' => $c->nom,
                'service' => $c->service,
                'prix' => $c->tarif?->prix,
                'actif' => $c->actif,
                'lits' => $c->lits->map(fn (Lit $l): array => [
                    'uuid' => $l->uuid,
                    'numero' => $l->numero,
                    'actif' => $l->actif,
                    'patient' => $occupes->get($l->id)?->dossier->nom,
                    'sejour_uuid' => $occupes->get($l->id)?->uuid,
                ])->values(),
            ]),
        ]);
    }

    public function creerChambre(Request $request): JsonResponse
    {
        /** @var array{nom: string, service?: string|null, lits: int, prix?: int|null} $donnees */
        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'service' => 'sometimes|nullable|string|max:40',
            'lits' => 'required|integer|between:1,60',
            'prix' => 'sometimes|nullable|integer|min:0',
        ]);
        $c = $this->hospit->creerChambre($this->etablissement(), $donnees);

        return response()->json(['chambre' => ['uuid' => $c->uuid, 'nom' => $c->nom]], 201);
    }

    public function modifierChambre(Request $request, string $uuid): JsonResponse
    {
        $c = Chambre::where('etablissement_id', $this->etablissement()->id)->where('uuid', $uuid)->firstOrFail();
        $donnees = $request->validate([
            'nom' => 'sometimes|string|max:100',
            'service' => 'sometimes|nullable|string|max:40',
            'actif' => 'sometimes|boolean',
            'prix' => 'sometimes|nullable|integer|min:0',
            'ajouter_lits' => 'sometimes|integer|between:1,60',
        ]);
        $c->update(array_intersect_key($donnees, array_flip(['nom', 'service', 'actif'])));
        if (array_key_exists('prix', $donnees)) {
            $this->hospit->fixerPrix($c, is_int($donnees['prix']) ? $donnees['prix'] : null);
        }
        if (is_int($donnees['ajouter_lits'] ?? null)) {
            $this->hospit->ajouterLits($c, $donnees['ajouter_lits']);
        }

        return response()->json(['chambre' => ['uuid' => $c->uuid, 'nom' => $c->nom]]);
    }

    /** Les patients hospitalisés. */
    public function sejours(): JsonResponse
    {
        $sejours = Hospitalisation::where('etablissement_id', $this->etablissement()->id)
            ->where('statut', 'en_cours')->orderBy('entree_at')->get();

        return response()->json(['data' => $sejours->map(fn (Hospitalisation $h): array => [
            ...$this->hospit->pourAffichage($h),
            'en_retard' => collect($this->hospit->plan($h))->where('en_retard', true)->count(),
        ])]);
    }

    public function admettre(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $donnees = $request->validate(['lit_uuid' => 'required|string', 'motif' => 'required|string|max:255']);
        $visite = Visite::where('etablissement_id', $etab->id)->where('uuid', $uuid)->firstOrFail();
        $lit = $this->lit($donnees['lit_uuid']);

        return $this->faire(fn () => $this->hospit->admettre(
            $etab, $this->dossiers->pourVisite($visite), $visite, $lit, $donnees['motif'], $this->currentUser(),
        ), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['sejour' => $this->hospit->pourAffichage($this->sejour($uuid), true)]);
    }

    /** Le bracelet scanné au lit ouvre le séjour. */
    public function lire(Request $request): JsonResponse
    {
        $lu = Qr::lire($request->validate(['qr' => 'required|string|max:200'])['qr']);
        if ($lu === null || $lu[0] !== Hospitalisations::QR) {
            return response()->json(['message' => 'Ce QR n’est pas un bracelet de patient.'], 422);
        }

        return $this->show($lu[1]);
    }

    public function transferer(Request $request, string $uuid): JsonResponse
    {
        $lit = $this->lit($request->validate(['lit_uuid' => 'required|string'])['lit_uuid']);

        return $this->faire(fn () => $this->hospit->transferer($this->sejour($uuid), $lit));
    }

    public function sortie(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'mode_sortie' => 'required|in:gueri,ameliore,transfere,contre_avis,evade,decede',
            'resume' => 'sometimes|nullable|string|max:5000',
        ]);

        return $this->faire(fn () => $this->hospit->sortir(
            $this->sejour($uuid), $donnees['mode_sortie'], $donnees['resume'] ?? null, $this->currentUser(),
        ));
    }

    public function prescrire(Request $request, string $uuid): JsonResponse
    {
        /** @var array{produit: string, dose?: string|null, voie: string, horaires: list<string>, fin?: string|null, instructions?: string|null} $donnees */
        $donnees = $request->validate([
            'produit' => 'required|string|max:255',
            'dose' => 'sometimes|nullable|string|max:60',
            'voie' => 'required|in:po,iv,im,sc,autre',
            'horaires' => 'required|array|min:1|max:12',
            'horaires.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'fin' => 'sometimes|nullable|date|after_or_equal:today',
            'instructions' => 'sometimes|nullable|string|max:500',
        ]);

        return $this->faire(function () use ($uuid, $donnees): Hospitalisation {
            $h = $this->sejour($uuid);
            $this->hospit->prescrire($h, $donnees, $this->currentUser());

            return $h;
        }, 201);
    }

    public function arreter(string $uuid): JsonResponse
    {
        $soin = SoinPrescrit::where('uuid', $uuid)->firstOrFail();
        $h = Hospitalisation::whereKey($soin->hospitalisation_id)->where('etablissement_id', $this->etablissement()->id)->firstOrFail();
        $soin->update(['arrete_at' => now()]);

        return response()->json(['sejour' => $this->hospit->pourAffichage($h->refresh(), true)]);
    }

    public function administrer(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'soin_uuid' => 'required|string',
            'prevue_at' => 'required|date',
            'statut' => 'required|in:faite,refusee,omise',
            'bracelet' => 'required_if:statut,faite|nullable|string|max:200',
            'commentaire' => 'sometimes|nullable|string|max:500',
        ]);
        $h = $this->sejour($uuid);
        $soin = SoinPrescrit::where('uuid', $donnees['soin_uuid'])->firstOrFail();

        return $this->faire(function () use ($h, $soin, $donnees): Hospitalisation {
            $this->hospit->administrer(
                $h, $soin, Carbon::parse((string) $donnees['prevue_at']), (string) $donnees['statut'],
                $donnees['bracelet'] ?? null, $donnees['commentaire'] ?? null, $this->currentUser(),
            );

            return $h;
        });
    }

    // ------------------------------------------------------------ interne

    /**
     * @param  callable(): Hospitalisation  $action
     */
    private function faire(callable $action, int $code = 200): JsonResponse
    {
        try {
            $h = $action();
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sejour' => $this->hospit->pourAffichage($h->refresh(), true)], $code);
    }

    private function sejour(string $uuid): Hospitalisation
    {
        return Hospitalisation::where('etablissement_id', $this->etablissement()->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function lit(string $uuid): Lit
    {
        $lit = Lit::with('chambre')->where('uuid', $uuid)->firstOrFail();
        abort_unless($lit->chambre->etablissement_id === $this->etablissement()->id, 404);

        return $lit;
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }
}
