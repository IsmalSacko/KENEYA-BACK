<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cpn;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Grossesse;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use App\Sante\Maternite\Maternite;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La maternité : grossesses, CPN, accouchements, carnet de vaccination.
 *
 * @tag Maternité
 */
class MaterniteController extends Controller
{
    public function __construct(private readonly Maternite $maternite, private readonly Dossiers $dossiers) {}

    /**
     * Les grossesses suivies ici, le terme le plus proche d'abord ;
     * `?statut=terminees` : les accouchées et les closes, les plus récentes
     * d'abord.
     */
    public function index(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $terminees = $request->query('statut') === 'terminees';
        $grossesses = Grossesse::with('dossier')
            ->where('etablissement_id', $this->etablissement()->id)
            ->when($terminees, fn ($r) => $r->where('statut', '!=', 'en_cours')->latest('terminee_at'))
            ->when(! $terminees, fn ($r) => $r->where('statut', 'en_cours')->orderBy('dpa'))
            ->when(is_string($q) && $q !== '', fn ($r) => $r->whereHas('dossier', fn ($d) => $d->where('nom', 'like', "%{$q}%")))
            ->limit(200)->get();

        return response()->json(['data' => $grossesses->map(fn (Grossesse $g): array => $this->maternite->pourAffichage($g))]);
    }

    /** La grossesse en cours de la patiente de la visite (ou null). */
    public function pourVisite(string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $dossier = $this->dossiers->pourVisite($this->visite($uuid));
        $g = Grossesse::where('dossier_id', $dossier->id)->where('statut', 'en_cours')->first();
        $visible = $g !== null && ($g->etablissement_id === $etab->id || $this->dossiers->accesHistorique($dossier, $etab));

        return response()->json(['grossesse' => $visible ? $this->maternite->pourAffichage($g) : null]);
    }

    public function declarer(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        /** @var array{ddr: string, gestite?: int|null, parite?: int|null, risques?: string|null} $donnees */
        $donnees = $request->validate([
            'ddr' => 'required|date|before_or_equal:today|after:-310 days',
            'gestite' => 'sometimes|nullable|integer|between:1,30',
            'parite' => 'sometimes|nullable|integer|between:0,30',
            'risques' => 'sometimes|nullable|string|max:1000',
        ]);
        try {
            $g = $this->maternite->declarer($etab, $this->dossiers->pourVisite($this->visite($uuid)), $donnees);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g)], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json(['grossesse' => $this->maternite->pourAffichage($this->grossesse($uuid))]);
    }

    /** Corriger la DDR, la gestité, la parité, les risques. */
    public function modifier(Request $request, string $uuid): JsonResponse
    {
        /** @var array{ddr?: string, gestite?: int|null, parite?: int|null, risques?: string|null} $donnees */
        $donnees = $request->validate([
            'ddr' => 'sometimes|date|before_or_equal:today|after:-310 days',
            'gestite' => 'sometimes|nullable|integer|between:1,30',
            'parite' => 'sometimes|nullable|integer|between:0,30',
            'risques' => 'sometimes|nullable|string|max:1000',
        ]);
        $g = $this->grossesse($uuid);
        $this->maternite->modifier($g, $donnees);

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g->refresh())]);
    }

    /** Clore la grossesse sans accouchement ici (fausse couche, IVG, GEU, transférée, perdue de vue). */
    public function terminer(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'issue' => 'required|in:'.implode(',', Maternite::ISSUES),
            'le' => 'sometimes|date|before_or_equal:today',
        ]);
        $g = $this->grossesse($uuid);
        $this->maternite->terminer($g, $donnees['issue'], $donnees['le'] ?? now()->toDateString());

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g->refresh())]);
    }

    public function cpn(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate($this->reglesCpn());
        $g = $this->grossesse($uuid);
        try {
            $this->maternite->cpn($g, $donnees, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g->refresh())], 201);
    }

    /** Corriger la dernière CPN. */
    public function modifierCpn(Request $request, string $uuid): JsonResponse
    {
        $cpn = Cpn::where('uuid', $uuid)->firstOrFail();
        // Seule la CPN notée ici se corrige ici.
        abort_unless($cpn->etablissement_id === $this->etablissement()->id, 404);
        $g = Grossesse::with('dossier')->findOrFail($cpn->grossesse_id);
        $donnees = $request->validate($this->reglesCpn());
        $this->maternite->modifierCpn($g, $cpn, $donnees);

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g->refresh())]);
    }

    public function accoucher(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'date_heure' => 'required|date|before_or_equal:now',
            'mode' => 'required|in:voie_basse,ventouse,cesarienne',
            'observations' => 'sometimes|nullable|string|max:3000',
            'enfants' => 'required|array|min:1|max:5',
            'enfants.*.sexe' => 'required|in:F,M',
            'enfants.*.vivant' => 'sometimes|boolean',
            'enfants.*.prenom' => 'sometimes|nullable|string|max:100',
            'enfants.*.poids_g' => 'sometimes|nullable|integer|between:300,7000',
            'enfants.*.taille_cm' => 'sometimes|nullable|integer|between:20,70',
            'enfants.*.apgar1' => 'sometimes|nullable|integer|between:0,10',
            'enfants.*.apgar5' => 'sometimes|nullable|integer|between:0,10',
        ]);
        /** @var list<array{sexe: string, vivant?: bool, poids_g?: int|null, taille_cm?: int|null, apgar1?: int|null, apgar5?: int|null, prenom?: string|null}> $enfants */
        $enfants = array_values($donnees['enfants']);
        $g = $this->grossesse($uuid);
        try {
            $this->maternite->accoucher($g, [
                'date_heure' => (string) $donnees['date_heure'],
                'mode' => (string) $donnees['mode'],
                'observations' => $donnees['observations'] ?? null,
            ], $enfants, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grossesse' => $this->maternite->pourAffichage($g->refresh())], 201);
    }

    public function carnet(string $uuid): JsonResponse
    {
        $enfant = $this->dossierConnu($uuid);

        return response()->json([...$this->dossiers->identite($enfant), 'carnet' => $this->maternite->carnet($enfant)]);
    }

    public function vacciner(Request $request, string $uuid): JsonResponse
    {
        $donnees = $request->validate([
            'vaccin' => 'required|string|max:20',
            'fait_le' => 'sometimes|date|before_or_equal:today',
            'lot' => 'sometimes|nullable|string|max:40',
        ]);
        $enfant = $this->dossierConnu($uuid);
        try {
            $this->maternite->vacciner($enfant, $donnees['vaccin'], $donnees['fait_le'] ?? now()->toDateString(), $donnees['lot'] ?? null, $this->currentUser());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([...$this->dossiers->identite($enfant), 'carnet' => $this->maternite->carnet($enfant)]);
    }

    /** Un vaccin noté par erreur. */
    public function annulerVaccin(string $uuid, string $vaccin): JsonResponse
    {
        $enfant = $this->dossierConnu($uuid);
        $this->maternite->annulerVaccin($enfant, $vaccin, $this->etablissement());

        return response()->json([...$this->dossiers->identite($enfant), 'carnet' => $this->maternite->carnet($enfant)]);
    }

    // ------------------------------------------------------------ patient

    /** Sa grossesse en cours, et ses enfants avec leur carnet. */
    public function maMaternite(): JsonResponse
    {
        $mere = $this->dossiers->pourPersonne($this->currentPersonne());
        $g = Grossesse::where('dossier_id', $mere->id)->where('statut', 'en_cours')->first();

        return response()->json([
            'grossesse' => $g === null ? null : $this->maternite->pourAffichage($g),
            'enfants' => Dossier::where('responsable_id', $mere->id)->orderBy('date_naissance')->get()
                ->map(fn (Dossier $e): array => [...$this->dossiers->identite($e), 'carnet' => $this->maternite->carnet($e)]),
        ]);
    }

    // ------------------------------------------------------------ interne

    /**
     * @return array<string, mixed>
     */
    private function reglesCpn(): array
    {
        return [
            'poids' => 'sometimes|nullable|numeric|between:25,200',
            'ta_sys' => 'sometimes|nullable|integer|between:60,250',
            'ta_dia' => 'sometimes|nullable|integer|between:30,160',
            'hauteur_uterine' => 'sometimes|nullable|integer|between:5,50',
            'bcf' => 'sometimes|nullable|integer|between:60,220',
            'presentation' => 'sometimes|nullable|in:cephalique,siege,transverse',
            'oedemes' => 'sometimes|nullable|boolean',
            'albuminurie' => 'sometimes|nullable|in:negatif,traces,+,++,+++',
            'tpi' => 'sometimes|nullable|integer|between:1,6',
            'vat' => 'sometimes|nullable|integer|between:1,5',
            'fer' => 'sometimes|boolean',
            'moustiquaire' => 'sometimes|boolean',
            'observations' => 'sometimes|nullable|string|max:3000',
            'prochain_rdv' => 'sometimes|nullable|date|after:today',
        ];
    }

    private function grossesse(string $uuid): Grossesse
    {
        $g = Grossesse::with('dossier')->where('uuid', $uuid)->firstOrFail();
        $etab = $this->etablissement();
        abort_unless($g->etablissement_id === $etab->id || $this->dossiers->accesHistorique($g->dossier, $etab), 404);

        return $g;
    }

    private function dossierConnu(string $uuid): Dossier
    {
        $dossier = Dossier::where('uuid', $uuid)->firstOrFail();
        abort_unless($this->dossiers->connu($dossier, $this->etablissement()), 404);

        return $dossier;
    }

    private function visite(string $uuid): Visite
    {
        return Visite::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }
}
