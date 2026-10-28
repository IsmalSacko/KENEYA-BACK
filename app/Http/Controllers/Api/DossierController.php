<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccesDossier;
use App\Models\DemandeExamen;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\ExamenClinique;
use App\Models\LectureDossier;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le dossier patient et la consultation médicale.
 *
 * @tag Dossier
 */
class DossierController extends Controller
{
    public function __construct(private readonly Dossiers $dossiers) {}

    // ------------------------------------------------------------ équipe

    /** Les patients reçus ici (identité seulement). */
    public function index(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $requete = Dossier::where(fn ($q) => $q->where('etablissement_id', $etab->id)
            ->orWhereIn('id', Visite::where('etablissement_id', $etab->id)->whereNotNull('dossier_id')->select('dossier_id'))
            ->orWhereIn('id', AccesDossier::where('etablissement_id', $etab->id)->select('dossier_id')));
        $q = $request->query('q');
        if (is_string($q) && $q !== '') {
            $requete->where(fn ($w) => $w->where('nom', 'like', "%{$q}%")->orWhere('telephone', 'like', "%{$q}%"));
        }

        return response()->json([
            // Les patients vus le plus récemment d'abord.
            'data' => $requete->latest('updated_at')->limit(100)->get()->map(fn (Dossier $d): array => $this->dossiers->identite($d)),
        ]);
    }

    /**
     * Le dossier du patient de la visite (créé au besoin), ouvert et tracé,
     * avec les constantes prises au tri.
     */
    public function pourVisite(string $uuid): JsonResponse
    {
        $visite = Visite::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'dossier' => $this->dossiers->lire($this->dossiers->pourVisite($visite), $this->currentUser()),
            'tri' => $this->dossiers->tri($visite),
        ]);
    }

    /** Le tri : l'infirmier prend les constantes avant le médecin. */
    public function trier(Request $request, string $uuid): JsonResponse
    {
        $visite = Visite::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();
        $constantes = $this->constantes($request->validate($this->reglesConstantes())['constantes'] ?? []);
        $visite->update(['constantes' => $constantes, 'constantes_at' => now(), 'constantes_par' => $this->currentUser()->id]);
        $this->dossiers->pourVisite($visite);

        return response()->json(['tri' => $this->dossiers->tri($visite)]);
    }

    /** Le dossier, et le tri de la visite en cours ici s'il y en a un. */
    public function show(string $uuid): JsonResponse
    {
        $dossier = $this->dossierConnu($uuid);
        $enCours = Visite::where('etablissement_id', $this->currentUser()->etablissement_id)
            ->where('dossier_id', $dossier->id)->where('statut', 'ouverte')
            ->whereNotNull('constantes')->latest('ouverte_at')->first();

        return response()->json([
            'dossier' => $this->dossiers->lire($dossier, $this->currentUser()),
            'tri' => $enCours !== null ? $this->dossiers->tri($enCours) : null,
        ]);
    }

    public function modifier(Request $request, string $uuid): JsonResponse
    {
        $dossier = $this->dossierConnu($uuid);
        $dossier->update($this->validerIdentite($request));

        return response()->json(['dossier' => $this->dossiers->identite($dossier)]);
    }

    public function demanderCode(string $uuid): JsonResponse
    {
        try {
            $this->dossiers->demanderCode($this->dossierConnu($uuid));
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Un code vient d’être envoyé au patient par SMS.']);
    }

    public function accorderParCode(Request $request, string $uuid): JsonResponse
    {
        $code = $request->validate(['code' => 'required|string|max:10'])['code'];
        $dossier = $this->dossierConnu($uuid);
        try {
            $this->dossiers->accorderParCode($dossier, $this->etablissement(), $code);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['dossier' => $this->dossiers->lire($dossier, $this->currentUser())]);
    }

    public function accorderSurPlace(string $uuid): JsonResponse
    {
        $dossier = $this->dossierConnu($uuid);
        try {
            $this->dossiers->accorderSurPlace($dossier, $this->etablissement());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['dossier' => $this->dossiers->lire($dossier, $this->currentUser())]);
    }

    /** Le soignant scanne le QR d'accord de l'app du patient. */
    public function accorderParQr(Request $request): JsonResponse
    {
        $qr = $request->validate(['qr' => 'required|string|max:200'])['qr'];
        try {
            $dossier = $this->dossiers->accorderParQr($qr, $this->etablissement());
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['dossier' => $this->dossiers->lire($dossier, $this->currentUser())]);
    }

    /** La consultation médicale de la visite. */
    public function consulter(Request $request, string $uuid): JsonResponse
    {
        $visite = Visite::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();
        $donnees = $request->validate($this->reglesConsultation(true));
        $dossier = $this->dossiers->pourVisite($visite);
        $examen = ExamenClinique::create([
            ...$donnees,
            // Sans constantes saisies, celles du tri.
            'constantes' => $this->constantes($donnees['constantes'] ?? []) ?: ($visite->constantes ?? []),
            'dossier_id' => $dossier->id,
            'visite_id' => $visite->id,
            'etablissement_id' => $visite->etablissement_id,
            'praticien_id' => $this->currentUser()->id,
        ]);
        $dossier->touch();

        return response()->json(['consultation' => $this->dossiers->examen($examen, $this->etablissement(), $this->currentUser())], 201);
    }

    /** Le médecin corrige sa consultation (le jour même). */
    public function corriger(Request $request, string $uuid): JsonResponse
    {
        $examen = ExamenClinique::where('etablissement_id', $this->currentUser()->etablissement_id)->where('uuid', $uuid)->firstOrFail();
        if (! $this->dossiers->modifiable($examen, $this->currentUser())) {
            throw new Refus('Une consultation se corrige par son auteur, le jour même.');
        }
        $donnees = $request->validate($this->reglesConsultation(false));
        if (array_key_exists('constantes', $donnees)) {
            $donnees['constantes'] = $this->constantes($donnees['constantes']);
        }
        $examen->update($donnees);

        return response()->json(['consultation' => $this->dossiers->examen($examen, $this->etablissement(), $this->currentUser())]);
    }

    // ------------------------------------------------------------ patient

    /** Son dossier complet, qui y a accès, qui l'a ouvert. */
    public function monDossier(): JsonResponse
    {
        $dossier = $this->dossiers->pourPersonne($this->currentPersonne());

        return response()->json([
            ...$this->dossiers->identite($dossier),
            'consultations' => ExamenClinique::with('etablissement', 'praticien')->where('dossier_id', $dossier->id)->latest()->get()
                ->map(fn (ExamenClinique $e): array => $this->dossiers->examen($e)),
            'analyses' => DemandeExamen::where('dossier_id', $dossier->id)->where('statut', 'validee')->latest()->get()
                ->map(fn (DemandeExamen $d): array => $this->dossiers->resultats($d)),
            'acces' => AccesDossier::with('etablissement')->where('dossier_id', $dossier->id)
                ->whereNull('revoque_at')->where('expire_at', '>', now())->latest()->get()
                ->map(fn (AccesDossier $a): array => [
                    'id' => $a->id,
                    'etablissement' => $a->etablissement->nom,
                    'par' => $a->par,
                    'expire_at' => $a->expire_at->toIso8601String(),
                ]),
            'lectures' => LectureDossier::with('etablissement', 'membre')->where('dossier_id', $dossier->id)
                ->latest('lu_at')->limit(50)->get()
                ->map(fn (LectureDossier $l): array => [
                    'etablissement' => $l->etablissement->nom,
                    'par' => $l->membre?->getAttribute('name'),
                    'historique' => $l->historique,
                    'lu_at' => $l->lu_at->toIso8601String(),
                ]),
        ]);
    }

    /** Ce que la personne déclare elle-même : allergies, antécédents… */
    public function modifierMonDossier(Request $request): JsonResponse
    {
        $dossier = $this->dossiers->pourPersonne($this->currentPersonne());
        $dossier->update($this->validerIdentite($request));

        return response()->json(['dossier' => $this->dossiers->identite($dossier)]);
    }

    public function accord(): JsonResponse
    {
        return response()->json($this->dossiers->genererAccord($this->currentPersonne()));
    }

    public function retirerAcces(int $id): JsonResponse
    {
        $dossier = $this->dossiers->pourPersonne($this->currentPersonne());
        AccesDossier::where('dossier_id', $dossier->id)->whereKey($id)->update(['revoque_at' => now()]);

        return response()->json(['message' => 'Accès retiré.']);
    }

    // ------------------------------------------------------------ interne

    /**
     * @return array<string, mixed>
     */
    private function reglesConsultation(bool $creation): array
    {
        return [
            'motif' => ($creation ? 'required' : 'sometimes').'|string|max:255',
            ...$this->reglesConstantes(),
            'examen' => 'sometimes|nullable|string|max:5000',
            'diagnostic' => 'sometimes|nullable|string|max:2000',
            'cim10' => 'sometimes|nullable|string|max:10',
            'conclusion' => 'sometimes|nullable|string|max:2000',
            'prochain_rdv' => 'sometimes|nullable|date|after_or_equal:today',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesConstantes(): array
    {
        return [
            'constantes' => 'sometimes|array',
            'constantes.ta_sys' => 'sometimes|nullable|integer|between:40,300',
            'constantes.ta_dia' => 'sometimes|nullable|integer|between:20,200',
            'constantes.temperature' => 'sometimes|nullable|numeric|between:30,45',
            'constantes.poids' => 'sometimes|nullable|numeric|between:0.3,400',
            'constantes.taille' => 'sometimes|nullable|integer|between:20,250',
            'constantes.pouls' => 'sometimes|nullable|integer|between:20,250',
            'constantes.spo2' => 'sometimes|nullable|integer|between:50,100',
            'constantes.glycemie' => 'sometimes|nullable|numeric|between:0.1,10',
        ];
    }

    /**
     * Les constantes renseignées, en nombres.
     *
     * @return array<string, int|float>
     */
    private function constantes(mixed $saisies): array
    {
        $propres = [];
        foreach (is_array($saisies) ? $saisies : [] as $cle => $v) {
            if (is_string($cle) && is_numeric($v)) {
                $propres[$cle] = $v + 0;
            }
        }

        return $propres;
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }

    private function dossierConnu(string $uuid): Dossier
    {
        $dossier = Dossier::where('uuid', $uuid)->firstOrFail();
        if (! $this->dossiers->connu($dossier, $this->etablissement())) {
            abort(404);
        }

        return $dossier;
    }

    /**
     * @return array<string, mixed>
     */
    private function validerIdentite(Request $request): array
    {
        /** @var array<string, mixed> $donnees */
        $donnees = $request->validate([
            'date_naissance' => 'sometimes|nullable|date|before_or_equal:today',
            'sexe' => 'sometimes|nullable|in:F,M',
            'groupe_sanguin' => 'sometimes|nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'allergies' => 'sometimes|nullable|string|max:1000',
            'antecedents' => 'sometimes|nullable|string|max:3000',
            'traitements' => 'sometimes|nullable|string|max:2000',
        ]);

        return $donnees;
    }
}
