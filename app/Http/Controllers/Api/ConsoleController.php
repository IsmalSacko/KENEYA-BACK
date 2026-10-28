<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\DemandeAbonnement;
use App\Models\Etablissement;
use App\Models\Formule;
use App\Models\FormuleTarif;
use App\Models\User;
use App\Sante\Abonnements\Abonnements;
use App\Sante\Module;
use App\Sante\Profil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ngoni Santé vu par la Console Ngoni (toutes les applications IsmaelDev),
 * selon son contrat v1 : un « compte » est un établissement. Voir
 * `ngoni-console/docs/CONTRAT.md`.
 */
class ConsoleController extends Controller
{
    private const MODULES = [
        'file' => 'File d’attente', 'rdv' => 'Rendez-vous', 'consultation' => 'Consultation',
        'caisse' => 'Caisse et reçus', 'pharmacie' => 'Pharmacie et stock', 'labo' => 'Laboratoire',
        'maternite' => 'Maternité et vaccins', 'hospitalisation' => 'Hospitalisation',
        'badges' => 'Badges', 'assurances' => 'Assurances',
        'ecrans' => 'Écran de salle et borne', 'rapport' => 'Rapport du mois',
    ];

    /** Ce que Ngoni Santé prend en charge du contrat v1.1. */
    private const CAPACITES = ['preuve', 'revoquer', 'approuver_a_vie', 'recettes', 'historique'];

    public function __construct(private readonly Abonnements $abonnements) {}

    public function resume(): JsonResponse
    {
        // Les établissements de l'éditeur (démos, essais) ne comptent pas.
        $clients = Etablissement::where('interne', false);
        $ids = (clone $clients)->pluck('id');
        $abos = Abonnement::whereIn('etablissement_id', $ids)->get();
        $enCours = $abos->filter(fn (Abonnement $a): bool => $a->enCours());
        $essais = $enCours->where('formule', Formule::ESSAI);

        return response()->json([
            'application' => [
                'code' => 'ngoni-sante',
                'nom' => 'Ngoni Santé',
                'capacites' => self::CAPACITES,
                'moyens' => array_map(
                    static fn (string $code): array => ['code' => $code, 'libelle' => DemandeAbonnement::LIBELLES_MOYENS[$code]],
                    DemandeAbonnement::MOYENS,
                ),
            ],
            'comptes' => $ids->count(),
            'internes' => Etablissement::where('interne', true)->count(),
            'a_valider' => (clone $clients)->whereNull('valide_at')->count(),
            'nouveaux_30j' => (clone $clients)->where('created_at', '>=', now()->subDays(30))->count(),
            'essais' => $essais->count(),
            'essais_bientot_finis' => $essais->filter(fn (Abonnement $a): bool => $a->fin !== null && $a->fin->lte(today()->addDays(3)))->count(),
            'abonnes' => $enCours->count() - $essais->count(),
            'echus' => $abos->count() - $enCours->count(),
            'demandes_en_attente' => DemandeAbonnement::where('statut', 'en_attente')->whereIn('etablissement_id', $ids)->count(),
            'encaisse_mois' => $this->encaisseDuMois($ids->all()),
        ]);
    }

    /** Les demandes des établissements (pas les paiements notés par la console), `q` : compte, téléphone, note, référence. */
    public function demandes(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => 'sometimes|in:en_attente,approuvee,refusee,annulee,toutes',
            'q' => 'sometimes|nullable|string|max:100',
        ]);
        $statut = $request->string('statut', 'en_attente')->toString();
        $q = trim($request->string('q')->toString());
        $liste = DemandeAbonnement::with(['etablissement', 'demandeur', 'decideur'])
            ->where('accord', false)
            ->when($statut !== 'toutes', fn ($r) => $r->where('statut', $statut))
            ->when($q !== '', fn ($r) => $r->where(fn ($r) => $r
                ->whereHas('etablissement', fn ($e) => $e->where('nom', 'like', "%{$q}%"))
                ->orWhere('telephone', 'like', "%{$q}%")
                ->orWhere('note', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")))
            ->latest()->limit(300)->get();

        return response()->json(['data' => $liste->map(fn (DemandeAbonnement $d): array => $this->demande($d))->values()]);
    }

    public function preuve(string $id): StreamedResponse
    {
        $d = DemandeAbonnement::where('uuid', $id)->firstOrFail();
        abort_if($d->preuve_chemin === null || ! Storage::exists($d->preuve_chemin), 404);

        return Storage::download($d->preuve_chemin);
    }

    /**
     * Valider, au montant demandé ou à un montant négocié (`montant`, `note`),
     * avec le vrai moyen et la référence du paiement, ou sans échéance (`a_vie`).
     */
    public function approuver(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'montant' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:255',
            'moyen' => ['nullable', Rule::in(DemandeAbonnement::MOYENS)],
            'reference' => 'nullable|string|max:100',
            'a_vie' => 'sometimes|boolean',
        ]);
        $d = $this->abonnements->approuver(
            DemandeAbonnement::where('uuid', $id)->firstOrFail(),
            null,
            isset($v['montant']) && is_numeric($v['montant']) ? (int) $v['montant'] : null,
            $this->texte($v, 'note'),
            $this->texte($v, 'moyen'),
            $this->texte($v, 'reference'),
            ($v['a_vie'] ?? false) === true,
            $this->operateur($request),
        );

        return response()->json(['demande' => $this->demande($d->load(['etablissement', 'demandeur', 'decideur']))]);
    }

    public function refuser(Request $request, string $id): JsonResponse
    {
        $motif = $request->validate(['motif' => 'required|string|max:255'])['motif'];
        $d = $this->abonnements->refuser(DemandeAbonnement::where('uuid', $id)->firstOrFail(), null, (string) $motif, $this->operateur($request));

        return response()->json(['demande' => $this->demande($d->load(['etablissement', 'demandeur', 'decideur']))]);
    }

    public function comptes(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        $filtre = $request->string('filtre')->toString();
        $liste = Etablissement::query()
            ->when($q !== '', fn ($r) => $r->where('nom', 'like', "%{$q}%"))
            ->when($filtre === 'a_valider', fn ($r) => $r->whereNull('valide_at'))
            ->when($filtre === 'interne', fn ($r) => $r->where('interne', true), fn ($r) => $r->where('interne', false))
            ->latest()->limit(500)->get();
        $lignes = collect($this->lignes($liste));

        $lignes = match ($filtre) {
            'essai' => $lignes->filter(fn (array $l): bool => $l['formule'] === Formule::ESSAI && $l['en_cours']),
            'abonne' => $lignes->filter(fn (array $l): bool => $l['formule'] !== Formule::ESSAI && $l['en_cours']),
            'echu' => $lignes->filter(fn (array $l): bool => ! $l['en_cours']),
            default => $lignes,
        };

        return response()->json(['data' => $lignes->values()]);
    }

    /** La fiche d'un compte : ses champs, ses détails, l'historique de son abonnement. */
    public function compte(string $id): JsonResponse
    {
        $e = Etablissement::where('uuid', $id)->firstOrFail();
        $telephone = $e->getAttribute('telephone');
        $adresse = $e->getAttribute('adresse');

        return response()->json([
            'compte' => $this->lignes(collect([$e]))[0] ?? null,
            'details' => [
                ['libelle' => 'N° d’autorisation', 'valeur' => $e->autorisation],
                ['libelle' => 'Responsable', 'valeur' => $e->responsable],
                ['libelle' => 'Téléphone de l’établissement', 'valeur' => is_string($telephone) ? $telephone : null],
                ['libelle' => 'Adresse', 'valeur' => is_string($adresse) ? $adresse : null],
                ['libelle' => 'Validé le', 'valeur' => $e->valide_at?->toDateString()],
            ],
            'historique' => $this->abonnements->historique($e),
        ]);
    }

    public function valider(string $id): JsonResponse
    {
        $e = Etablissement::where('uuid', $id)->firstOrFail();
        $e->update(['valide_at' => $e->valide_at ?? now()]);

        return response()->json(['message' => "{$e->nom} est validé : les patients le voient."]);
    }

    /** Marquer un établissement comme étant à l'éditeur (ou plus) : hors des chiffres. */
    public function interne(Request $request, string $id): JsonResponse
    {
        $e = Etablissement::where('uuid', $id)->firstOrFail();
        $e->update(['interne' => $request->validate(['interne' => 'required|boolean'])['interne'] === true]);

        return response()->json(['message' => $e->interne ? "{$e->nom} est marqué comme à vous." : "{$e->nom} redevient un client."]);
    }

    public function accorder(Request $request, string $id): JsonResponse
    {
        $e = Etablissement::where('uuid', $id)->firstOrFail();
        $d = $request->validate([
            'formule' => ['required', Rule::exists('formules', 'code')],
            'fin' => 'nullable|date',
            'note' => 'nullable|string|max:255',
            'debut' => 'nullable|date',
            'montant' => 'nullable|integer|min:0',
            'moyen' => ['nullable', Rule::in(DemandeAbonnement::MOYENS)],
            'reference' => 'nullable|string|max:100',
        ]);
        $fin = isset($d['fin']) && is_string($d['fin']) ? Carbon::parse($d['fin']) : null;
        $debut = isset($d['debut']) && is_string($d['debut']) ? Carbon::parse($d['debut']) : null;
        $this->abonnements->accorder(
            $e,
            (string) $d['formule'],
            $fin,
            null,
            $this->texte($d, 'note'),
            $debut,
            isset($d['montant']) && is_numeric($d['montant']) ? (int) $d['montant'] : null,
            $this->texte($d, 'moyen'),
            $this->texte($d, 'reference'),
            $this->operateur($request),
        );

        return response()->json(['message' => 'Formule enregistrée.']);
    }

    /** L'abonnement s'arrête maintenant, avec un motif. */
    public function revoquer(Request $request, string $id): JsonResponse
    {
        $e = Etablissement::where('uuid', $id)->firstOrFail();
        $motif = $request->validate(['motif' => 'required|string|max:255'])['motif'];
        $this->abonnements->revoquer($e, (string) $motif, null, $this->operateur($request));

        return response()->json(['message' => "L’abonnement de {$e->nom} est arrêté."]);
    }

    /** Tout ce qui a été encaissé entre deux dates : demandes approuvées et paiements notés en accordant. */
    public function recettes(Request $request): JsonResponse
    {
        $v = $request->validate(['du' => 'nullable|date', 'au' => 'nullable|date']);
        $du = isset($v['du']) && is_string($v['du']) ? Carbon::parse($v['du'])->startOfDay() : now()->startOfMonth();
        $au = isset($v['au']) && is_string($v['au']) ? Carbon::parse($v['au'])->endOfDay() : now()->endOfDay();
        $liste = DemandeAbonnement::with('etablissement')
            ->where('statut', 'approuvee')
            ->whereBetween('decide_at', [$du, $au])
            ->whereIn('etablissement_id', Etablissement::where('interne', false)->select('id'))
            ->orderByDesc('decide_at')->get();
        $noms = Formule::pluck('nom', 'code');

        $totaux = [];
        foreach ($liste as $d) {
            $totaux[$d->devise] = ($totaux[$d->devise] ?? 0) + $d->montant;
        }

        return response()->json([
            'data' => $liste->map(fn (DemandeAbonnement $d): array => [
                'id' => $d->uuid,
                'le' => $d->decide_at?->toIso8601String(),
                'compte' => ['id' => $d->etablissement->uuid, 'nom' => $d->etablissement->nom],
                'formule' => $d->formule,
                'formule_nom' => $noms[$d->formule] ?? $d->formule,
                'mois' => $d->mois,
                'montant' => $d->montant,
                'devise' => $d->devise,
                'moyen' => $d->moyen,
                'reference' => $d->reference,
                'frais' => 0,
                'recu_numero' => $d->recu_numero,
                'automatique' => false,
                'accord' => $d->accord,
            ])->values(),
            'totaux' => array_map(
                static fn (string $devise, int $total): array => ['devise' => $devise, 'total' => $total, 'frais' => 0],
                array_keys($totaux),
                array_values($totaux),
            ),
        ]);
    }

    public function formules(): JsonResponse
    {
        $data = [];
        foreach ($this->abonnements->catalogue(tout: true) as $f) {
            $inclus = [];
            foreach ([...(array) $f['modules'], ...(array) $f['fonctions']] as $m) {
                if (is_string($m)) {
                    $inclus[] = self::MODULES[$m] ?? $m;
                }
            }
            $data[] = [
                'code' => $f['code'],
                'nom' => $f['nom'],
                'description' => $f['description'],
                'essai' => $f['code'] === Formule::ESSAI,
                'jours_essai' => $f['jours_essai'],
                'max_membres' => $f['max_membres'],
                'actif' => $f['actif'],
                'inclus' => $inclus,
                'tarifs' => $f['tarifs'],
                'champs' => [
                    [
                        'cle' => 'modules', 'libelle' => 'Modules ouverts', 'type' => 'choix_multiple',
                        'options' => array_map(static fn (Module $m): array => ['valeur' => $m->value, 'libelle' => $m->label()], Module::cases()),
                        'valeur' => $f['modules'],
                    ],
                    [
                        'cle' => 'fonctions', 'libelle' => 'Fonctions en plus', 'type' => 'choix_multiple',
                        'options' => array_map(
                            static fn (string $code, string $libelle): array => ['valeur' => $code, 'libelle' => $libelle],
                            array_keys(Formule::FONCTIONS),
                            array_values(Formule::FONCTIONS),
                        ),
                        'valeur' => $f['fonctions'],
                    ],
                ],
            ];
        }

        return response()->json(['data' => $data]);
    }

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
            'modules.*' => [Rule::in(Module::values())],
            'fonctions' => 'sometimes|array',
            'fonctions.*' => [Rule::in(array_keys(Formule::FONCTIONS))],
            'champs' => 'sometimes|array:modules,fonctions',
            'champs.modules' => 'sometimes|array',
            'champs.modules.*' => [Rule::in(Module::values())],
            'champs.fonctions' => 'sometimes|array',
            'champs.fonctions.*' => [Rule::in(array_keys(Formule::FONCTIONS))],
            'tarifs' => 'sometimes|array',
            'tarifs.*.mois' => 'required_with:tarifs|integer|in:1,3,6,12',
            // null : la durée n'est plus proposée.
            'tarifs.*.montant' => 'present|nullable|integer|min:0',
        ]);
        $maj = array_intersect_key($d, array_flip(['nom', 'description', 'jours_essai', 'max_membres', 'actif', 'modules', 'fonctions']));
        foreach (['modules', 'fonctions'] as $cle) {
            if (is_array($d['champs'] ?? null) && is_array($d['champs'][$cle] ?? null)) {
                $maj[$cle] = array_values(array_unique(array_filter($d['champs'][$cle], is_string(...))));
            }
        }

        DB::transaction(function () use ($f, $d, $maj): void {
            $f->update($maj);
            foreach (is_array($d['tarifs'] ?? null) ? $d['tarifs'] : [] as $t) {
                if (! is_array($t) || ! is_numeric($t['mois'] ?? null)) {
                    continue;
                }
                $cle = ['formule_id' => $f->id, 'mois' => (int) $t['mois'], 'devise' => 'XOF'];
                if (is_numeric($t['montant'] ?? null)) {
                    FormuleTarif::updateOrCreate($cle, ['montant' => (int) $t['montant']]);
                } else {
                    FormuleTarif::where($cle)->delete();
                }
            }
        });

        return $this->formules();
    }

    /**
     * @return array<string, mixed>
     */
    private function demande(DemandeAbonnement $d): array
    {
        $base = $this->abonnements->demande($d);

        return [
            'id' => $d->uuid,
            'compte' => ['id' => $d->etablissement->uuid, 'nom' => $d->etablissement->nom],
            'interne' => $d->etablissement->interne,
            ...array_diff_key($base, ['uuid' => true]),
            'frais' => 0,
            'automatique' => false,
            'note_decision' => $d->statut === 'refusee' ? $d->motif_refus : $d->note_validation,
            'par' => $d->decide_par_nom ?? $d->decideur?->nom,
            'demandeur' => $d->demandeur?->nom,
            'demandeur_telephone' => $d->demandeur?->telephone,
        ];
    }

    /**
     * Les comptes tels que la console les liste.
     *
     * @param  Collection<int, Etablissement>  $liste
     * @return array<int, array<string, mixed>>
     */
    private function lignes(Collection $liste): array
    {
        $abos = Abonnement::whereIn('etablissement_id', $liste->pluck('id'))->get()->keyBy('etablissement_id');
        $membres = User::whereIn('etablissement_id', $liste->pluck('id'))->where('actif', true)
            ->groupBy('etablissement_id')->select('etablissement_id', DB::raw('COUNT(*) as n'))->pluck('n', 'etablissement_id');
        $admins = User::whereIn('etablissement_id', $liste->pluck('id'))->where('role', 'admin_etablissement')
            ->orderBy('id')->get(['etablissement_id', 'telephone'])->unique('etablissement_id')->pluck('telephone', 'etablissement_id');
        $noms = Formule::pluck('nom', 'code');

        return $liste->map(function (Etablissement $e) use ($abos, $membres, $admins, $noms): array {
            $a = $abos->get($e->id);
            $profil = Profil::tryFrom($e->profil);

            return [
                'id' => $e->uuid,
                'nom' => $e->nom,
                'type' => $e->profil,
                'type_libelle' => $profil?->label() ?? $e->profil,
                'pays' => $e->pays_code,
                'responsable' => $e->responsable,
                'autorisation' => $e->autorisation,
                'telephone' => $admins[$e->id] ?? null,
                'valide' => $e->valide_at !== null,
                'cree_le' => ($cree = $e->getAttribute('created_at')) instanceof Carbon ? $cree->toDateString() : null,
                'membres' => is_numeric($n = $membres[$e->id] ?? 0) ? (int) $n : 0,
                'formule' => $a?->formule,
                'formule_nom' => $a !== null ? ($noms[$a->formule] ?? $a->formule) : null,
                'fin' => $a?->fin?->toDateString(),
                'en_cours' => $a === null || $a->enCours(),
                'manuel' => $a !== null && $a->manuel,
                'note' => $a?->note,
                'interne' => $e->interne,
            ];
        })->values()->all();
    }

    /** Qui agit, pour le journal : « Console · Ismaila SACKO ». */
    private function operateur(Request $request): string
    {
        $nom = trim($request->headers->get('X-Console-Operateur') ?? '');

        return $nom === '' ? 'Console' : 'Console · '.mb_substr($nom, 0, 100);
    }

    /**
     * @param  array<array-key, mixed>  $v
     */
    private function texte(array $v, string $cle): ?string
    {
        $t = $v[$cle] ?? null;

        return is_string($t) && trim($t) !== '' ? trim($t) : null;
    }

    /**
     * @param  array<mixed>  $ids  les établissements clients
     * @return list<array{devise: string, total: int}>
     */
    private function encaisseDuMois(array $ids): array
    {
        $lignes = [];
        $sommes = DemandeAbonnement::where('statut', 'approuvee')->where('decide_at', '>=', now()->startOfMonth())->whereIn('etablissement_id', $ids)
            ->groupBy('devise')->select('devise', DB::raw('SUM(montant) as total'))->get();
        foreach ($sommes as $r) {
            $devise = $r->getAttribute('devise');
            $total = $r->getAttribute('total');
            $lignes[] = ['devise' => is_string($devise) ? $devise : 'XOF', 'total' => is_numeric($total) ? (int) $total : 0];
        }

        return $lignes;
    }
}
