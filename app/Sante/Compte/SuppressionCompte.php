<?php

declare(strict_types=1);

namespace App\Sante\Compte;

use App\Enums\Role;
use App\Models\Abonnement;
use App\Models\AccesDossier;
use App\Models\DemandeAbonnement;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\JournalAbonnement;
use App\Models\Personne;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Refus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * La personne supprime son compte Ngoni Santé depuis l'application (règle
 * 5.1.1(v) de l'App Store). Ce qui part, et ce qui reste :
 *
 * - le compte : nom, numéro, PIN, carte santé, téléphones des notifications,
 *   codes reçus ; le numéro peut ensuite servir à une nouvelle inscription ;
 * - ses accès pro : dans un établissement qui continue (un autre
 *   administrateur y reste), son accès se ferme ; son nom reste sur les actes
 *   qu'il y a faits (traçabilité des soins), sans son numéro. S'il en était
 *   le propriétaire, un autre administrateur le devient ;
 * - un établissement dont il est le seul administrateur est supprimé avec
 *   tout son contenu (patients, consultations, ventes, stock, équipe,
 *   abonnement). Les reçus d'abonnement payés sont copiés à part
 *   (storage/app/suppressions/), pour la comptabilité. Les établissements de
 *   l'éditeur (démonstrations) ne sont jamais supprimés ici ;
 * - le dossier médical : ce que des établissements y ont noté leur appartient
 *   et reste chez eux pour la durée légale de conservation, sans lien avec un
 *   compte ; les accès donnés par le patient sont retirés. Un dossier qu'aucun
 *   établissement n'a tenu part avec le compte.
 *
 * Un abonnement payé sur l'App Store n'est pas résilié : Apple seul le peut.
 */
final class SuppressionCompte
{
    /** Où un établissement a noté quelque chose dans un dossier : il le garde. */
    private const TRACES_DOSSIER = [
        'numeros_dossier', 'visites', 'examens_cliniques', 'demandes_examen', 'grossesses', 'naissances',
        'vaccinations', 'hospitalisations', 'rendez_vous',
    ];

    /**
     * Ce que la suppression emporterait, sans rien toucher.
     *
     * @return array{etablissements_supprimes: list<array{nom: string, patients: int, equipe: int}>, etablissements_gardes: list<array{nom: string, repreneur: string|null}>, dossier: string|null, abonnement_apple: bool, blocage: string|null}
     */
    public function apercu(Personne $personne): array
    {
        $plan = $this->plan($personne);
        $supprimes = array_map(fn (Etablissement $e): int => $e->id, $plan['supprimes']);
        $dossier = Dossier::where('personne_id', $personne->id)->first();

        return [
            'etablissements_supprimes' => array_map(fn (Etablissement $e): array => [
                'nom' => $e->nom,
                'patients' => Dossier::where(fn ($q) => $q->where('etablissement_id', $e->id)
                    ->orWhereIn('id', Visite::where('etablissement_id', $e->id)->whereNotNull('dossier_id')->select('dossier_id')))->count(),
                'equipe' => User::where('etablissement_id', $e->id)->where('actif', true)->count(),
            ], $plan['supprimes']),
            'etablissements_gardes' => array_map(fn (array $g): array => [
                'nom' => $g['etablissement']->nom,
                'repreneur' => $g['repreneur']?->name,
            ], $plan['gardes']),
            'dossier' => $dossier === null ? null : ($this->tenu($dossier, $supprimes) ? 'garde' : 'supprime'),
            // Payé sur l'App Store : Apple continue de le facturer tant qu'il n'est pas résilié.
            'abonnement_apple' => Abonnement::whereIn('etablissement_id', $supprimes)->whereNotNull('apple_transaction_originale')->get()
                ->contains(fn (Abonnement $a): bool => $a->enCours()),
            'blocage' => $this->blocage($personne),
        ];
    }

    /**
     * @return array{etablissements_supprimes: int, acces_fermes: int, dossier: string|null, sauvegarde: string|null}
     *
     * @throws Refus
     */
    public function supprimer(Personne $personne): array
    {
        $blocage = $this->blocage($personne);
        if ($blocage !== null) {
            throw new Refus($blocage);
        }

        $plan = $this->plan($personne);
        $supprimes = array_map(fn (Etablissement $e): int => $e->id, $plan['supprimes']);
        $sauvegarde = $this->sauvegarderRecus($personne, $plan['supprimes']);
        $resume = ['etablissements_supprimes' => count($supprimes), 'acces_fermes' => 0, 'dossier' => null, 'sauvegarde' => $sauvegarde];

        DB::transaction(function () use ($personne, $plan, $supprimes, &$resume): void {
            // Les établissements qui continuent : un autre administrateur en devient propriétaire.
            foreach ($plan['gardes'] as ['etablissement' => $etablissement, 'repreneur' => $repreneur]) {
                if ((int) $etablissement->proprietaire_id === $personne->id) {
                    $etablissement->forceFill(['proprietaire_id' => $repreneur?->personne_id])->save();
                }
            }

            // Ses accès là-bas se ferment ; son nom reste sur ses actes, pas son numéro
            // (remplacé par une marque unique : un numéro par établissement).
            $acces = User::where('personne_id', $personne->id)->whereNotIn('etablissement_id', $supprimes)->get();
            foreach ($acces as $membre) {
                $membre->forceFill(['personne_id' => null, 'telephone' => 'supprime-'.$membre->id, 'actif' => false, 'badge_jeton' => null])->save();
                $membre->delete();
            }
            $resume['acces_fermes'] = $acces->count();

            // Les établissements dont il était le seul administrateur, avec tout leur contenu.
            foreach ($plan['supprimes'] as $etablissement) {
                User::withTrashed()->where('etablissement_id', $etablissement->id)->forceDelete();
                $etablissement->forceDelete();
            }

            $dossier = Dossier::where('personne_id', $personne->id)->first();
            if ($dossier !== null && $this->tenu($dossier)) {
                AccesDossier::where('dossier_id', $dossier->id)->whereNull('revoque_at')->update(['revoque_at' => now()]);
                $dossier->forceFill(['personne_id' => null])->save();
                $resume['dossier'] = 'garde';
            } elseif ($dossier !== null) {
                $dossier->delete();
                $resume['dossier'] = 'supprime';
            }

            DB::table('codes_verification')->where('telephone', $personne->telephone)->delete();
            // La personne, et avec elle ses téléphones (cascade) ; plus rien ne la désigne.
            $personne->forceDelete();
        });

        Log::warning('Compte supprimé par son titulaire', [
            'personne' => $personne->uuid, 'nom' => $personne->nom, 'telephone' => $personne->telephone,
            'etablissements_supprimes' => array_map(fn (Etablissement $e): string => $e->uuid, $plan['supprimes']),
            ...$resume,
        ]);

        return $resume;
    }

    /** Ce qui interdit la suppression ici, ou null. */
    private function blocage(Personne $personne): ?string
    {
        return (bool) $personne->getAttribute('admin_plateforme')
            ? 'Compte de l’équipe Ngoni Santé : il ne se supprime pas depuis l’application.'
            : null;
    }

    /**
     * Les établissements où la personne administre : supprimés si elle en est
     * la seule administratrice, gardés sinon (avec celui qui reprend).
     *
     * @return array{supprimes: list<Etablissement>, gardes: list<array{etablissement: Etablissement, repreneur: User|null}>}
     */
    private function plan(Personne $personne): array
    {
        $membres = User::where('personne_id', $personne->id)->get();
        $ids = $membres->pluck('etablissement_id')
            ->merge(Etablissement::where('proprietaire_id', $personne->id)->pluck('id'))
            ->filter()->unique()->values();

        $plan = ['supprimes' => [], 'gardes' => []];
        foreach (Etablissement::whereIn('id', $ids)->orderBy('nom')->get() as $etablissement) {
            $proprietaire = (int) $etablissement->proprietaire_id === $personne->id;
            $admin = $proprietaire || $membres->contains(fn (User $m): bool => $m->getAttribute('etablissement_id') == $etablissement->id
                && in_array($m->role, Role::etablissementAdmins(), true));
            if (! $admin) {
                continue;
            }
            $repreneur = User::where('etablissement_id', $etablissement->id)
                ->whereIn('role', Role::etablissementAdmins())->where('actif', true)
                ->where(fn ($q) => $q->whereNull('personne_id')->orWhere('personne_id', '!=', $personne->id))
                ->orderBy('id')->first();
            if ($repreneur === null && ! $etablissement->interne) {
                $plan['supprimes'][] = $etablissement;
            } else {
                $plan['gardes'][] = ['etablissement' => $etablissement, 'repreneur' => $repreneur];
            }
        }

        return $plan;
    }

    /**
     * Un établissement (autre que ceux qui partent) a noté quelque chose dans
     * ce dossier : il lui appartient aussi, et reste.
     *
     * @param  list<int>  $sauf  les établissements supprimés avec le compte
     */
    private function tenu(Dossier $dossier, array $sauf = []): bool
    {
        if ($dossier->etablissement_id !== null && ! in_array((int) $dossier->etablissement_id, $sauf, true)) {
            return true;
        }
        foreach (self::TRACES_DOSSIER as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'dossier_id')) {
                continue;
            }
            $requete = DB::table($table)->where('dossier_id', $dossier->id);
            if ($sauf !== [] && Schema::hasColumn($table, 'etablissement_id')) {
                $requete->whereNotIn('etablissement_id', $sauf);
            }
            if ($requete->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les reçus d'abonnement payés des établissements supprimés, copiés à part :
     * des recettes de l'éditeur, à garder pour la comptabilité. Aucune donnée
     * de santé.
     *
     * @param  list<Etablissement>  $etablissements
     */
    private function sauvegarderRecus(Personne $personne, array $etablissements): ?string
    {
        $ids = array_map(fn (Etablissement $e): int => $e->id, $etablissements);
        $recus = DemandeAbonnement::whereIn('etablissement_id', $ids)->where('statut', 'approuvee')->get();
        if ($recus->isEmpty()) {
            return null;
        }

        $chemin = 'suppressions/'.now()->format('Y-m-d-His').'-'.$personne->uuid.'.json';
        Storage::disk('local')->put($chemin, (string) json_encode([
            'supprime_le' => now()->toIso8601String(),
            'par' => 'titulaire du compte',
            'etablissements' => array_map(fn (Etablissement $e): array => ['uuid' => $e->uuid, 'nom' => $e->nom], $etablissements),
            'demandes_abonnement' => $recus->map(fn (DemandeAbonnement $d): array => $d->attributesToArray())->all(),
            'abonnements' => Abonnement::whereIn('etablissement_id', $ids)->get()->map(fn (Abonnement $a): array => $a->attributesToArray())->all(),
            'journal_abonnement' => JournalAbonnement::whereIn('etablissement_id', $ids)->get()->map(fn (JournalAbonnement $j): array => $j->attributesToArray())->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $chemin;
    }
}
