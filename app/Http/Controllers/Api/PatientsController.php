<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccesDossier;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\RendezVous;
use App\Models\Ticket;
use App\Models\Visite;
use App\Sante\Dossier\Dossiers;
use App\Sante\Refus;
use App\Sante\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Les patients de l'établissement, vus de l'accueil : retrouver, créer,
 * corriger l'identité, voir ses visites. Rien de médical ici : le contenu
 * du dossier reste aux soignants (droit `dossier.lire`).
 *
 * @tag Patients
 */
class PatientsController extends Controller
{
    public function __construct(private readonly Dossiers $dossiers) {}

    /** Les patients vus ici, les plus récents d'abord ; recherche nom ou téléphone. */
    public function index(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $requete = Dossier::where(fn ($q) => $q->where('etablissement_id', $etab->id)
            ->orWhereIn('id', Visite::where('etablissement_id', $etab->id)->whereNotNull('dossier_id')->select('dossier_id'))
            ->orWhereIn('id', AccesDossier::where('etablissement_id', $etab->id)->select('dossier_id')));
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $chiffres = preg_replace('/\D/', '', $q);
            if (is_string($chiffres) && $chiffres !== '' && preg_match('/^[\d\s+-]+$/', trim($q)) === 1) {
                // Des chiffres : le numéro de dossier (« 26-4821 », « 4821 »), ou un bout du téléphone.
                $requete->where(fn ($w) => $w->whereIn('id', DB::table('numeros_dossier')->where('etablissement_id', $etab->id)
                    ->whereRaw("REPLACE(numero, '-', '') like ?", ["%{$chiffres}%"])->select('dossier_id'))
                    ->when(strlen($chiffres) >= 4, fn ($x) => $x->orWhere('telephone', 'like', "%{$chiffres}%")));
            } else {
                // « Diaby Ousmane », « ous dia » : chaque mot, dans n'importe quel ordre.
                foreach (preg_split('/\s+/', trim($q)) ?: [] as $mot) {
                    $requete->where('nom', 'like', '%'.$mot.'%');
                }
            }
        }

        $dossiers = $requete->latest('updated_at')->limit(100)->get();
        // Sa dernière visite ici, et s'il est dans le centre en ce moment.
        $visites = Visite::where('etablissement_id', $etab->id)->whereIn('dossier_id', $dossiers->pluck('id'))
            ->latest('ouverte_at')->get(['dossier_id', 'ouverte_at', 'statut'])->groupBy('dossier_id');
        $numeros = $this->dossiers->numeros($dossiers, $etab);

        return response()->json([
            'data' => $dossiers->map(function (Dossier $d) use ($visites, $numeros): array {
                $derniere = $visites->get($d->id)?->first();

                return [
                    ...$this->dossiers->identiteAccueil($d),
                    'numero' => $numeros[$d->id],
                    'derniere_visite' => $derniere?->ouverte_at->toIso8601String(),
                    'en_cours' => $derniere !== null && $derniere->statut === 'ouverte' && $derniere->ouverte_at->isToday(),
                ];
            }),
        ]);
    }

    /**
     * Nouveau patient à l'accueil. S'il est déjà connu (même nom, même
     * numéro), c'est sa fiche qui revient : pas de doublon.
     */
    public function creer(Request $request): JsonResponse
    {
        $etab = $this->etablissement();
        $donnees = $this->valider($request, true);
        $dossier = $this->dossiers->trouverOuCreer($etab, [...$donnees, 'nom' => $donnees['nom'] ?? '']);
        $existant = ! $dossier->wasRecentlyCreated;
        if ($existant) {
            // Ce qu'on vient d'apprendre complète la fiche, sans rien effacer.
            $dossier->update(array_filter($donnees, fn ($v, string $k): bool => $v !== null && $dossier->getAttribute($k) === null, ARRAY_FILTER_USE_BOTH));
        }

        return response()->json(['patient' => $this->fiche($dossier, $etab), 'existant' => $existant], $existant ? 200 : 201);
    }

    /** La fiche : identité et visites ici. */
    public function show(string $uuid): JsonResponse
    {
        $etab = $this->etablissement();

        return response()->json(['patient' => $this->fiche($this->patient($uuid, $etab), $etab)]);
    }

    public function modifier(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $dossier = $this->patient($uuid, $etab);
        $dossier->update($this->valider($request, false));

        return response()->json(['patient' => $this->fiche($dossier, $etab)]);
    }

    /**
     * PIN oublié, sans SMS : le patient est à l'accueil, sa pièce d'identité
     * vérifiée ; un nouveau PIN lui est remis, qu'il changera.
     */
    public function nouveauPin(string $uuid): JsonResponse
    {
        $dossier = $this->patient($uuid, $this->etablissement());
        $personne = $dossier->personne_id !== null ? Personne::find($dossier->personne_id) : null;
        if ($personne === null) {
            return response()->json(['message' => 'Ce patient n’a pas l’app Ngoni Santé : pas de PIN à changer.'], 422);
        }
        $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $personne->update(['pin' => $pin]);

        return response()->json(['pin' => $pin]);
    }

    /** Deux fiches pour la même personne : celle-ci garde tout, l'autre disparaît. */
    public function fusionner(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $doublon = $request->validate(['doublon_uuid' => 'required|uuid'])['doublon_uuid'];
        try {
            $garde = $this->dossiers->fusionner($this->patient($uuid, $etab), $this->patient($doublon, $etab), $etab);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['patient' => $this->fiche($garde, $etab)]);
    }

    // ------------------------------------------------------------ interne

    /**
     * @return array<string, mixed>
     */
    private function fiche(Dossier $dossier, Etablissement $etab): array
    {
        $visites = Visite::with('lignes')->where('etablissement_id', $etab->id)->where('dossier_id', $dossier->id)
            ->latest('ouverte_at')->limit(50)->get();
        $tickets = Ticket::with('service')->whereIn('visite_id', $visites->pluck('id'))->orderBy('id')->get()->groupBy('visite_id');

        $rdv = RendezVous::with('agenda')->where('etablissement_id', $etab->id)->where('dossier_id', $dossier->id)
            ->whereIn('statut', ['demande', 'confirme'])->where('debut', '>=', now()->startOfDay())
            ->orderBy('debut')->limit(10)->get();

        return [
            ...$this->dossiers->identiteAccueilAvecNumero($dossier, $etab),
            'rendez_vous' => $rdv->map(fn (RendezVous $r): array => [
                'uuid' => $r->uuid,
                'debut' => $r->debut?->toIso8601String(),
                'agenda' => $r->agenda?->nom,
                'motif' => $r->motif,
                'statut' => $r->statut,
            ])->values(),
            'visites' => $visites->map(fn (Visite $v): array => [
                'uuid' => $v->uuid,
                'ouverte_at' => $v->ouverte_at->toIso8601String(),
                'statut' => $v->statut,
                'services' => ($tickets->get($v->id) ?? collect())->map(fn (Ticket $t): string => $t->service->nom)->unique()->values(),
                // Ce qui a été fait : la consultation, les médicaments…
                'actes' => $v->lignes->pluck('libelle')->values(),
                'total' => $v->total,
                'reste' => $v->reste(),
                'devise' => $v->devise,
            ])->values(),
        ];
    }

    private function patient(string $uuid, Etablissement $etab): Dossier
    {
        $dossier = Dossier::where('uuid', $uuid)->firstOrFail();
        abort_unless($dossier->personne_id !== null || $this->dossiers->connu($dossier, $etab), 404);

        return $dossier;
    }

    /**
     * L'identité ; l'âge seul donne une date de naissance estimée.
     *
     * @return array{nom?: string, telephone?: string|null, sexe?: string|null, date_naissance?: string, naissance_estimee?: bool, adresse?: string|null}
     */
    private function valider(Request $request, bool $creation): array
    {
        $d = $request->validate([
            'nom' => ($creation ? 'required' : 'sometimes').'|string|max:120',
            'telephone' => 'sometimes|nullable|string|max:20',
            'sexe' => 'sometimes|nullable|in:F,M',
            'date_naissance' => 'sometimes|nullable|date|before_or_equal:today',
            'age' => 'sometimes|nullable|integer|between:0,120',
            'adresse' => 'sometimes|nullable|string|max:255',
        ]);
        $identite = [];
        if (is_string($d['nom'] ?? null) && trim($d['nom']) !== '') {
            $identite['nom'] = trim($d['nom']);
        }
        if (array_key_exists('telephone', $d)) {
            $tel = is_string($d['telephone']) ? trim($d['telephone']) : '';
            $pays = Pays::find($this->etablissement()->pays_code);
            $identite['telephone'] = $tel === '' ? null : ($pays !== null ? Telephone::normaliser($tel, $pays) : $tel);
        }
        if (array_key_exists('sexe', $d)) {
            $identite['sexe'] = is_string($d['sexe']) ? $d['sexe'] : null;
        }
        if (array_key_exists('adresse', $d)) {
            $identite['adresse'] = is_string($d['adresse']) && trim($d['adresse']) !== '' ? trim($d['adresse']) : null;
        }
        if (is_string($d['date_naissance'] ?? null)) {
            $identite['date_naissance'] = $d['date_naissance'];
            $identite['naissance_estimee'] = false;
        } elseif (is_int($d['age'] ?? null)) {
            $identite['date_naissance'] = now()->subYears($d['age'])->startOfYear()->toDateString();
            $identite['naissance_estimee'] = true;
        }

        return $identite;
    }

    private function etablissement(): Etablissement
    {
        return Etablissement::findOrFail($this->currentUser()->etablissement_id);
    }
}
