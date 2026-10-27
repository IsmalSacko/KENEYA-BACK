<?php

declare(strict_types=1);

namespace App\Sante\Labo;

use App\Models\Analyse;
use App\Models\DemandeAnalyse;
use App\Models\DemandeExamen;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Personne;
use App\Models\Tarif;
use App\Models\User;
use App\Models\Visite;
use App\Sante\Caisse\Caisse;
use App\Sante\Qr;
use App\Sante\Refus;
use App\Sante\Sms\EnvoiSms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le laboratoire : catalogue, demande (facturée sur la visite), prélèvement
 * avec étiquette QR, saisie, validation. Le patient est prévenu par SMS que
 * ses résultats sont prêts — jamais du résultat lui-même.
 */
final class Labo
{
    public const QR = 'labo';

    /**
     * Le catalogue de départ d'un centre de santé au Mali. Sans prix : chaque
     * établissement fixe les siens.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4?: string|null, 5?: int|float|null, 6?: int|float|null, 7?: list<string>, 8?: string|null}>
     */
    private const CATALOGUE = [
        // code, nom, catégorie, type, unité, min, max, choix, normal
        ['TDR', 'TDR paludisme', 'parasitologie', 'choix', null, null, null, ['Négatif', 'Positif'], 'Négatif'],
        ['GE', 'Goutte épaisse', 'parasitologie', 'choix', null, null, null, ['Négatif', 'Positif +', 'Positif ++', 'Positif +++'], 'Négatif'],
        ['HB', 'Hémoglobine', 'hematologie', 'nombre', 'g/dL', 12, 16],
        ['NFS', 'Numération formule sanguine', 'hematologie', 'texte'],
        ['GLY', 'Glycémie à jeun', 'biochimie', 'nombre', 'g/L', 0.7, 1.1],
        ['CREA', 'Créatinine', 'biochimie', 'nombre', 'mg/L', 6, 12],
        ['GRP', 'Groupe sanguin et Rhésus', 'immuno_hematologie', 'choix', null, null, null, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], null],
        ['BHCG', 'Test de grossesse', 'serologie', 'choix', null, null, null, ['Négatif', 'Positif'], null],
        ['VIH', 'Sérologie VIH', 'serologie', 'choix', null, null, null, ['Négatif', 'Positif', 'Indéterminé'], 'Négatif'],
        ['WIDAL', 'Sérodiagnostic de Widal', 'serologie', 'texte'],
        ['ALB', 'Albumine urinaire (bandelette)', 'urines', 'choix', null, null, null, ['Négatif', 'Traces', '+', '++', '+++'], 'Négatif'],
        ['ECBU', 'ECBU', 'bacteriologie', 'texte'],
        ['KAOP', 'Selles KAOP', 'parasitologie', 'texte'],
    ];

    public function __construct(private readonly Caisse $caisse, private readonly EnvoiSms $sms) {}

    /**
     * @return Collection<int, Analyse>
     */
    public function catalogue(Etablissement $etablissement, bool $inactives = false): Collection
    {
        if (! Analyse::withTrashed()->where('etablissement_id', $etablissement->id)->exists()) {
            foreach (self::CATALOGUE as $a) {
                Analyse::create([
                    'etablissement_id' => $etablissement->id,
                    'code' => $a[0],
                    'nom' => $a[1],
                    'categorie' => $a[2],
                    'type_resultat' => $a[3],
                    'unite' => $a[4] ?? null,
                    'normale_min' => $a[5] ?? null,
                    'normale_max' => $a[6] ?? null,
                    'choix' => $a[7] ?? null,
                    'choix_normal' => $a[8] ?? null,
                ]);
            }
        }

        return Analyse::with('tarif')->where('etablissement_id', $etablissement->id)
            ->when(! $inactives, fn ($q) => $q->where('actif', true))
            ->orderBy('categorie')->orderBy('nom')->get();
    }

    /**
     * Crée ou modifie une analyse ; `prix` crée ou met à jour l'acte du tarif
     * (catégorie labo), 0 ou null le retire.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrer(Etablissement $etablissement, array $donnees, ?Analyse $analyse = null): Analyse
    {
        $avecPrix = array_key_exists('prix', $donnees);
        $prix = is_numeric($donnees['prix'] ?? null) ? (int) $donnees['prix'] : 0;
        unset($donnees['prix']);

        return DB::transaction(function () use ($etablissement, $donnees, $analyse, $avecPrix, $prix): Analyse {
            $analyse ??= new Analyse(['etablissement_id' => $etablissement->id]);
            $analyse->fill($donnees)->save();

            if ($avecPrix) {
                $tarif = $analyse->tarif_id !== null ? Tarif::find($analyse->tarif_id) : null;
                if ($prix <= 0) {
                    $tarif?->delete();
                    $analyse->update(['tarif_id' => null]);
                } elseif ($tarif !== null) {
                    $tarif->update(['nom' => $analyse->nom, 'prix' => $prix]);
                } else {
                    $tarif = Tarif::create([
                        'etablissement_id' => $etablissement->id,
                        'nom' => $analyse->nom,
                        'categorie' => 'labo',
                        'prix' => $prix,
                        'actif' => true,
                    ]);
                    $analyse->update(['tarif_id' => $tarif->id]);
                }
            }

            return $analyse->refresh()->load('tarif');
        });
    }

    /**
     * La demande : les analyses facturées sur la visite quand il y en a une.
     * [libres] : celles qui ne sont pas au catalogue, écrites par le médecin
     * comme sur un bon papier (résultat en texte, prix posé à la caisse).
     *
     * @param  Collection<int, Analyse>  $analyses
     * @param  list<string>  $libres
     */
    public function demander(
        Etablissement $etablissement,
        Dossier $dossier,
        ?Visite $visite,
        Collection $analyses,
        ?User $par,
        bool $urgent = false,
        ?string $renseignements = null,
        array $libres = [],
    ): DemandeExamen {
        $libres = array_values(array_unique(array_filter(array_map('trim', $libres), fn (string $l): bool => $l !== '')));
        if ($analyses->isEmpty() && $libres === []) {
            throw new Refus('Choisissez au moins une analyse.');
        }

        return DB::transaction(function () use ($etablissement, $dossier, $visite, $analyses, $par, $urgent, $renseignements, $libres): DemandeExamen {
            $demande = DemandeExamen::create([
                'etablissement_id' => $etablissement->id,
                'dossier_id' => $dossier->id,
                'visite_id' => $visite?->id,
                'prescripteur_id' => $par?->id,
                'statut' => 'demandee',
                'urgent' => $urgent,
                'renseignements' => $renseignements,
            ]);
            foreach ($analyses as $a) {
                $demande->lignes()->create([
                    'analyse_id' => $a->id,
                    'nom' => $a->nom,
                    'type_resultat' => $a->type_resultat,
                    'unite' => $a->unite,
                    'normale' => $a->normale(),
                ]);
                if ($visite !== null && $a->tarif !== null) {
                    $this->caisse->ajouterLigne($visite, $a->tarif, null, null, 1, $par);
                }
            }

            foreach ($libres as $nom) {
                $demande->lignes()->create(['nom' => $nom, 'type_resultat' => 'texte']);
            }

            return $demande;
        });
    }

    public function prelever(DemandeExamen $demande, User $par): DemandeExamen
    {
        if ($demande->statut !== 'demandee') {
            throw new Refus('Cette demande est déjà prélevée.');
        }
        $demande->update(['statut' => 'prelevee', 'preleve_at' => now(), 'preleve_par' => $par->id]);

        return $demande;
    }

    /**
     * Saisit des résultats ; un nombre hors des normes, ou un choix autre que
     * le normal, est marqué anormal.
     *
     * @param  list<array{uuid: string, resultat?: string|null, commentaire?: string|null}>  $resultats
     */
    public function saisir(DemandeExamen $demande, array $resultats, User $par): DemandeExamen
    {
        if (in_array($demande->statut, ['validee', 'annulee'], true)) {
            throw new Refus('Ces résultats sont déjà validés.');
        }

        DB::transaction(function () use ($demande, $resultats, $par): void {
            if ($demande->statut === 'demandee') {
                $this->prelever($demande, $par);
            }
            $lignes = $demande->lignes()->with('analyse')->get()->keyBy('uuid');
            foreach ($resultats as $r) {
                $ligne = $lignes->get($r['uuid']);
                if ($ligne === null) {
                    continue;
                }
                $texte = trim((string) ($r['resultat'] ?? ''));
                $ligne->fill([
                    'commentaire' => $r['commentaire'] ?? $ligne->commentaire,
                    'saisi_par' => $par->id,
                    'saisi_at' => now(),
                    ...$this->interpreter($ligne, $texte),
                ])->save();
            }
        });

        return $demande;
    }

    public function valider(DemandeExamen $demande, User $par): DemandeExamen
    {
        if ($demande->statut === 'validee') {
            return $demande;
        }
        if ($demande->lignes()->whereNull('resultat')->exists()) {
            throw new Refus('Saisissez tous les résultats avant de valider.');
        }
        $demande->update(['statut' => 'validee', 'valide_at' => now(), 'valide_par' => $par->id]);

        $telephone = $this->telephone($demande->dossier);
        if ($telephone !== null) {
            $nom = $demande->etablissement->nom;
            $this->sms->envoyer($telephone, "Ngoni Santé : vos résultats d'analyses sont prêts ({$nom}). Ouvrez « Mon dossier » ou passez les chercher.");
        }

        return $demande;
    }

    public function annuler(DemandeExamen $demande): DemandeExamen
    {
        if ($demande->statut !== 'demandee') {
            throw new Refus('Le prélèvement est fait : la demande ne s’annule plus.');
        }
        $demande->update(['statut' => 'annulee']);

        return $demande;
    }

    /** L'étiquette du tube : retrouve la demande au labo. */
    public static function qr(DemandeExamen $demande): string
    {
        return Qr::creer(self::QR, $demande->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function pourAffichage(DemandeExamen $d): array
    {
        $d->loadMissing('lignes.analyse', 'dossier', 'etablissement', 'prescripteur', 'validateur');

        return [
            'uuid' => $d->uuid,
            'statut' => $d->statut,
            'urgent' => $d->urgent,
            'date' => $d->created_at?->toIso8601String(),
            'valide_at' => $d->valide_at?->toIso8601String(),
            'patient' => $d->dossier->nom,
            'telephone' => $this->telephone($d->dossier),
            'dossier_uuid' => $d->dossier->uuid,
            'etablissement' => $d->etablissement->nom,
            'prescripteur' => $d->prescripteur?->getAttribute('name'),
            'valide_par' => $d->validateur?->getAttribute('name'),
            'renseignements' => $d->renseignements,
            'qr' => self::qr($d),
            'lignes' => $d->lignes->map(fn (DemandeAnalyse $l): array => [
                'uuid' => $l->uuid,
                'nom' => $l->nom,
                'type_resultat' => $l->type_resultat,
                'unite' => $l->unite,
                'normale' => $l->normale,
                'choix' => $l->analyse->choix ?? [],
                'resultat' => $l->resultat,
                'anormal' => $l->anormal,
                'commentaire' => $l->commentaire,
            ])->values(),
        ];
    }

    /**
     * @return array{resultat: string|null, valeur: float|null, anormal: bool|null}
     */
    private function interpreter(DemandeAnalyse $ligne, string $texte): array
    {
        if ($texte === '') {
            return ['resultat' => null, 'valeur' => null, 'anormal' => null];
        }
        $analyse = $ligne->analyse;
        if ($ligne->type_resultat === 'nombre') {
            $nombre = str_replace([',', ' '], ['.', ''], $texte);
            if (! is_numeric($nombre)) {
                throw new Refus("{$ligne->nom} : un nombre est attendu.");
            }
            $v = (float) $nombre;
            $min = $analyse?->normale_min;
            $max = $analyse?->normale_max;

            return [
                'resultat' => $texte,
                'valeur' => $v,
                'anormal' => $min === null && $max === null ? null : (($min !== null && $v < $min) || ($max !== null && $v > $max)),
            ];
        }
        if ($ligne->type_resultat === 'choix') {
            $choix = $analyse->choix ?? [];
            if ($choix !== [] && ! in_array($texte, $choix, true)) {
                throw new Refus("{$ligne->nom} : choisissez parmi ".implode(', ', $choix).'.');
            }
            $normal = $analyse?->choix_normal;

            return ['resultat' => $texte, 'valeur' => null, 'anormal' => $normal === null ? null : $texte !== $normal];
        }

        return ['resultat' => $texte, 'valeur' => null, 'anormal' => null];
    }

    private function telephone(Dossier $dossier): ?string
    {
        if ($dossier->personne_id !== null) {
            return Personne::find($dossier->personne_id)?->telephone;
        }

        return $dossier->telephone;
    }
}
