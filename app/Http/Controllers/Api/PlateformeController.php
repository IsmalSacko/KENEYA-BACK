<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Models\FormuleTarif;
use App\Models\Personne;
use App\Models\User;
use App\Sante\Abonnements\Abonnements;
use App\Sante\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La console Ngoni Santé, comme celle de Ngoni Caisse : le tableau de bord,
 * les établissements (valider, accorder une formule), les demandes
 * d'abonnement (approuver, refuser), les formules et leurs tarifs.
 */
class PlateformeController extends Controller
{
    public function __construct(private readonly Abonnements $abonnements) {}

    public function tableau(): JsonResponse
    {
        $abos = Abonnement::all();
        $enCours = $abos->filter(fn (Abonnement $a): bool => $a->enCours());

        return response()->json([
            'etablissements' => Etablissement::count(),
            'a_valider' => Etablissement::whereNull('valide_at')->count(),
            'nouveaux_30j' => Etablissement::where('created_at', '>=', now()->subDays(30))->count(),
            'essais' => $enCours->where('formule', Formule::ESSAI)->count(),
            'payants' => $enCours->where('formule', '!=', Formule::ESSAI)->count(),
            'echus' => $abos->count() - $enCours->count(),
            'demandes_en_attente' => DemandeAbonnement::where('statut', 'en_attente')->count(),
            'encaisse_mois' => DemandeAbonnement::where('statut', 'approuvee')->where('decide_at', '>=', now()->startOfMonth())
                ->groupBy('devise')->select('devise', DB::raw('SUM(montant) as total'))->get()
                ->map(function ($r): array {
                    $devise = $r->getAttribute('devise');
                    $total = $r->getAttribute('total');

                    return ['devise' => is_string($devise) ? $devise : 'XOF', 'total' => is_numeric($total) ? (int) $total : 0];
                })->values(),
            'membres' => User::where('actif', true)->count(),
            'personnes' => Personne::count(),
        ]);
    }

    public function etablissements(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        $filtre = $request->string('filtre')->toString();
        $liste = Etablissement::query()
            ->when($q !== '', fn ($r) => $r->where('nom', 'like', "%{$q}%"))
            ->when($filtre === 'a_valider', fn ($r) => $r->whereNull('valide_at'))
            ->latest()->limit(200)->get();
        $abos = Abonnement::whereIn('etablissement_id', $liste->pluck('id'))->get()->keyBy('etablissement_id');
        $membres = User::whereIn('etablissement_id', $liste->pluck('id'))->where('actif', true)
            ->groupBy('etablissement_id')->select('etablissement_id', DB::raw('COUNT(*) as n'))->pluck('n', 'etablissement_id');
        $noms = Formule::pluck('nom', 'code');

        $lignes = $liste->map(function (Etablissement $e) use ($abos, $membres, $noms): array {
            $a = $abos->get($e->id);

            return [
                'uuid' => $e->uuid,
                'nom' => $e->nom,
                'profil' => $e->profil,
                'pays_code' => $e->pays_code,
                'responsable' => $e->responsable,
                'autorisation' => $e->autorisation,
                'valide' => $e->valide_at !== null,
                'cree_le' => ($cree = $e->getAttribute('created_at')) instanceof Carbon ? $cree->toDateString() : null,
                'membres' => is_numeric($n = $membres[$e->id] ?? 0) ? (int) $n : 0,
                'formule' => $a?->formule,
                'formule_nom' => $a !== null ? ($noms[$a->formule] ?? $a->formule) : null,
                'fin' => $a?->fin?->toDateString(),
                'en_cours' => $a?->enCours() ?? true,
                'manuel' => $a !== null && $a->manuel,
                'note' => $a?->note,
            ];
        })->values();

        $lignes = match ($filtre) {
            'essai' => $lignes->filter(fn (array $l): bool => $l['formule'] === Formule::ESSAI && $l['en_cours']),
            'payant' => $lignes->filter(fn (array $l): bool => $l['formule'] !== Formule::ESSAI && $l['en_cours']),
            'echu' => $lignes->filter(fn (array $l): bool => ! $l['en_cours']),
            default => $lignes,
        };

        return response()->json(['data' => $lignes->values()]);
    }

    public function valider(string $uuid): JsonResponse
    {
        $e = Etablissement::where('uuid', $uuid)->firstOrFail();
        $e->update(['valide_at' => $e->valide_at ?? now()]);

        return response()->json(['message' => "{$e->nom} est validé : les patients le voient."]);
    }

    public function accorder(Request $request, string $uuid): JsonResponse
    {
        $e = Etablissement::where('uuid', $uuid)->firstOrFail();
        $d = $request->validate([
            'formule' => ['required', Rule::exists('formules', 'code')],
            'fin' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ]);
        $fin = isset($d['fin']) && is_string($d['fin']) ? Carbon::parse($d['fin']) : null;
        $abo = $this->abonnements->accorder($e, (string) $d['formule'], $fin, $this->moi($request), isset($d['note']) ? (string) $d['note'] : null);

        return response()->json(['abonnement' => $this->abonnements->etat($abo->etablissement)]);
    }

    public function demandes(Request $request): JsonResponse
    {
        $statut = $request->string('statut', 'en_attente')->toString();
        $liste = DemandeAbonnement::with(['etablissement', 'demandeur'])
            ->when($statut !== 'toutes', fn ($q) => $q->where('statut', $statut))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $liste->map(fn (DemandeAbonnement $d): array => [
            ...$this->abonnements->demande($d),
            'etablissement' => $d->etablissement->nom,
            'etablissement_uuid' => $d->etablissement->uuid,
            'demandeur' => $d->demandeur?->nom,
            'demandeur_telephone' => $d->demandeur?->telephone,
        ])->values()]);
    }

    public function preuve(string $uuid): StreamedResponse
    {
        $d = DemandeAbonnement::where('uuid', $uuid)->firstOrFail();
        abort_if($d->preuve_chemin === null || ! Storage::exists($d->preuve_chemin), 404);

        return Storage::download($d->preuve_chemin);
    }

    public function approuver(Request $request, string $uuid): JsonResponse
    {
        $d = $this->abonnements->approuver(DemandeAbonnement::where('uuid', $uuid)->firstOrFail(), $this->moi($request));

        return response()->json(['demande' => $this->abonnements->demande($d)]);
    }

    public function refuser(Request $request, string $uuid): JsonResponse
    {
        $motif = $request->validate(['motif' => 'required|string|max:255'])['motif'];
        $d = $this->abonnements->refuser(DemandeAbonnement::where('uuid', $uuid)->firstOrFail(), $this->moi($request), (string) $motif);

        return response()->json(['demande' => $this->abonnements->demande($d)]);
    }

    public function formules(): JsonResponse
    {
        return response()->json([
            'data' => $this->abonnements->catalogue(tout: true),
            'modules' => array_map(static fn (Module $m): string => $m->value, Module::cases()),
            'fonctions' => ['ecrans', 'rapport'],
        ]);
    }

    /** Régler une formule : son nom, sa durée d'essai, ses limites, ses prix. */
    public function modifierFormule(Request $request, string $code): JsonResponse
    {
        $f = Formule::where('code', $code)->firstOrFail();
        $d = $request->validate([
            'nom' => 'sometimes|string|max:60',
            'description' => 'sometimes|nullable|string|max:255',
            'jours_essai' => 'sometimes|integer|min:0|max:365',
            'max_membres' => 'sometimes|nullable|integer|min:1|max:10000',
            'actif' => 'sometimes|boolean',
            'modules' => 'sometimes|array',
            'modules.*' => [Rule::in(array_map(static fn (Module $m): string => $m->value, Module::cases()))],
            'fonctions' => 'sometimes|array',
            'fonctions.*' => [Rule::in(['ecrans', 'rapport'])],
            'tarifs' => 'sometimes|array',
            'tarifs.*.mois' => 'required_with:tarifs|integer|in:1,3,6,12',
            'tarifs.*.montant' => 'required_with:tarifs|integer|min:0',
        ]);

        DB::transaction(function () use ($f, $d): void {
            $f->update(array_intersect_key($d, array_flip(['nom', 'description', 'jours_essai', 'max_membres', 'actif', 'modules', 'fonctions'])));
            if (isset($d['tarifs']) && is_array($d['tarifs'])) {
                foreach ($d['tarifs'] as $t) {
                    if (! is_array($t) || ! is_numeric($t['mois'] ?? null) || ! is_numeric($t['montant'] ?? null)) {
                        continue;
                    }
                    FormuleTarif::updateOrCreate(
                        ['formule_id' => $f->id, 'mois' => (int) $t['mois'], 'devise' => 'XOF'],
                        ['montant' => (int) $t['montant']],
                    );
                }
            }
        });

        return response()->json(['data' => $this->abonnements->catalogue(tout: true)]);
    }

    private function moi(Request $request): ?int
    {
        $p = $request->user('personne');

        return $p instanceof Personne ? $p->id : null;
    }
}
