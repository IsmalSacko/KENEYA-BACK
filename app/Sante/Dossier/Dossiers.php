<?php

declare(strict_types=1);

namespace App\Sante\Dossier;

use App\Models\AccesDossier;
use App\Models\DemandeAnalyse;
use App\Models\DemandeExamen;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\ExamenClinique;
use App\Models\LectureDossier;
use App\Models\Personne;
use App\Models\User;
use App\Models\Visite;
use App\Sante\CodesVerification;
use App\Sante\Qr;
use App\Sante\Refus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le dossier médical et le secret qui l'entoure : un établissement voit ce
 * qu'il a lui-même noté ; l'historique tenu ailleurs, seulement avec l'accord
 * du patient (QR de son app ou code SMS). Chaque ouverture est tracée.
 */
final class Dossiers
{
    /** Durée d'un accord du patient. */
    public const DUREE_ACCES_JOURS = 7;

    /** Validité du QR d'accord affiché par l'app du patient. */
    public const VALIDITE_QR_MIN = 10;

    public function __construct(private readonly CodesVerification $codes) {}

    /** Le dossier du patient de la visite (créé au besoin). */
    public function pourVisite(Visite $visite): Dossier
    {
        if ($visite->dossier_id !== null && ($d = Dossier::find($visite->dossier_id)) !== null) {
            return $d;
        }
        $personne = $visite->personne_id !== null ? Personne::find($visite->personne_id) : null;
        $dossier = $personne !== null
            ? $this->pourPersonne($personne)
            : $this->trouverOuCreer(Etablissement::findOrFail($visite->etablissement_id), [
                'nom' => $visite->nom ?? 'Patient',
                'telephone' => $visite->telephone,
            ]);
        $visite->update(['dossier_id' => $dossier->id]);

        return $dossier;
    }

    /**
     * Le patient de l'accueil : retrouvé s'il est déjà connu (même nom et même
     * numéro), sinon créé. Un numéro seul ne suffit pas : une mère vient
     * souvent avec ses enfants et son propre téléphone.
     *
     * @param  array{nom: string, telephone?: string|null, sexe?: string|null, date_naissance?: string, naissance_estimee?: bool, adresse?: string|null}  $identite
     */
    public function trouverOuCreer(Etablissement $etablissement, array $identite): Dossier
    {
        $nom = trim($identite['nom']);
        $telephone = $identite['telephone'] ?? null;
        if (is_string($telephone) && trim($telephone) !== '') {
            $personne = Personne::where('telephone', $telephone)->first();
            if ($personne !== null && self::memeNom($personne->nom, $nom)) {
                return $this->pourPersonne($personne);
            }
            $connu = Dossier::where('telephone', $telephone)->get()
                ->first(fn (Dossier $d): bool => self::memeNom($d->nom, $nom) && $this->connu($d, $etablissement));
            if ($connu !== null) {
                return $connu;
            }
        }

        return Dossier::create([...$identite, 'nom' => $nom, 'etablissement_id' => $etablissement->id]);
    }

    /**
     * Les constantes prises au tri sur la visite ; null s'il n'y en a pas.
     *
     * @return array{constantes: array<string, int|float>, at: string|null, par: string|null}|null
     */
    public function tri(Visite $visite): ?array
    {
        if ($visite->constantes === null || $visite->constantes === []) {
            return null;
        }

        return [
            'constantes' => $visite->constantes,
            'at' => $visite->constantes_at?->toIso8601String(),
            'par' => $visite->constantes_par !== null ? User::find($visite->constantes_par)?->name : null,
        ];
    }

    /** « Awa  TRAORÉ » et « awa traore » sont le même nom. */
    public static function memeNom(string $a, string $b): bool
    {
        $n = static fn (string $s): string => (string) preg_replace('/\s+/', ' ', strtolower(trim(Str::ascii($s))));

        return $n($a) === $n($b);
    }

    /**
     * Ce que l'accueil et la caisse voient : l'identité, rien de médical.
     *
     * @return array<string, mixed>
     */
    /** Les tables qui rangent quelque chose dans un dossier. */
    private const RATTACHES = [
        'visites', 'rendez_vous', 'acces_dossier', 'lectures_dossier', 'examens_cliniques', 'demandes_examen',
        'grossesses', 'naissances', 'vaccinations', 'hospitalisations',
    ];

    /**
     * Deux fiches pour la même personne : on garde [garde], on y range tout
     * ce qui était sur [doublon] (visites, rendez-vous, examens, grossesse,
     * vaccins…), on complète ce qui manquait, et le doublon disparaît.
     */
    public function fusionner(Dossier $garde, Dossier $doublon, Etablissement $etablissement): Dossier
    {
        if ($garde->id === $doublon->id) {
            throw new Refus('C’est la même fiche.');
        }
        if ($garde->personne_id !== null && $doublon->personne_id !== null) {
            throw new Refus('Ce sont deux personnes qui ont chacune leur compte Ngoni Santé : on ne peut pas les fusionner.');
        }

        return DB::transaction(function () use ($garde, $doublon, $etablissement): Dossier {
            foreach (self::RATTACHES as $table) {
                DB::table($table)->where('dossier_id', $doublon->id)->update(['dossier_id' => $garde->id]);
            }
            // Un seul numéro par centre : celui de la fiche gardée.
            $dejaNumerotes = DB::table('numeros_dossier')->where('dossier_id', $garde->id)->pluck('etablissement_id');
            DB::table('numeros_dossier')->where('dossier_id', $doublon->id)->whereIn('etablissement_id', $dejaNumerotes)->delete();
            DB::table('numeros_dossier')->where('dossier_id', $doublon->id)->update(['dossier_id' => $garde->id]);

            // Ce que la fiche gardée ne savait pas ; le médical s'additionne.
            $complement = [];
            foreach (['telephone', 'adresse', 'date_naissance', 'sexe', 'groupe_sanguin', 'responsable_id', 'rappel_vaccins_le'] as $champ) {
                if ($garde->getAttribute($champ) === null && $doublon->getAttribute($champ) !== null) {
                    $complement[$champ] = $doublon->getAttribute($champ);
                }
            }
            if ($garde->getAttribute('date_naissance') === null && $doublon->getAttribute('date_naissance') !== null) {
                $complement['naissance_estimee'] = $doublon->getAttribute('naissance_estimee');
            }
            foreach (['allergies', 'antecedents', 'traitements'] as $champ) {
                $texte = static fn (mixed $v): string => is_string($v) ? trim($v) : '';
                $a = $texte($garde->getAttribute($champ));
                $b = $texte($doublon->getAttribute($champ));
                if ($b !== '' && ! str_contains($a, $b)) {
                    $complement[$champ] = $a === '' ? $b : $a."\n".$b;
                }
            }
            $personne = $doublon->personne_id;
            $doublon->delete();
            if ($garde->personne_id === null && $personne !== null) {
                $complement['personne_id'] = $personne;
            }
            $garde->update($complement);
            $this->numero($garde, $etablissement);

            return $garde->refresh();
        });
    }

    /**
     * L'identité, avec le numéro du patient dans ce centre.
     *
     * @return array<string, mixed>
     */
    public function identiteAccueilAvecNumero(Dossier $dossier, Etablissement $etablissement): array
    {
        return [...$this->identiteAccueil($dossier), 'numero' => $this->numero($dossier, $etablissement)];
    }

    /**
     * Le numéro du patient dans ce centre (« 26-4821 ») : donné la première
     * fois que le centre le reçoit, puis il ne change plus.
     */
    public function numero(Dossier $dossier, Etablissement $etablissement): string
    {
        return $this->numeros(new Collection([$dossier]), $etablissement)[$dossier->id];
    }

    /**
     * Les numéros de plusieurs patients d'un coup ; ceux qui n'en ont pas
     * encore en reçoivent un.
     *
     * @param  Collection<int, Dossier>  $dossiers
     * @return array<int, string>
     */
    public function numeros(Collection $dossiers, Etablissement $etablissement): array
    {
        /** @var array<int, string> $numeros */
        $numeros = DB::table('numeros_dossier')->where('etablissement_id', $etablissement->id)
            ->whereIn('dossier_id', $dossiers->pluck('id'))->pluck('numero', 'dossier_id')->all();
        foreach ($dossiers as $d) {
            $numeros[$d->id] ??= $this->nouveauNumero($d, $etablissement);
        }

        return $numeros;
    }

    /** L'année, puis quatre chiffres au hasard, jamais deux fois dans le centre. */
    private function nouveauNumero(Dossier $dossier, Etablissement $etablissement): string
    {
        for ($essai = 0; $essai < 50; $essai++) {
            // Une année presque pleine (10 000 numéros) : un chiffre de plus.
            $chiffres = $essai < 40 ? 4 : 5;
            $numero = now()->format('y').'-'.str_pad((string) random_int(0, 10 ** $chiffres - 1), $chiffres, '0', STR_PAD_LEFT);
            try {
                DB::table('numeros_dossier')->insert([
                    'etablissement_id' => $etablissement->id, 'dossier_id' => $dossier->id, 'numero' => $numero,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $numero;
            } catch (UniqueConstraintViolationException) {
                // Déjà pris (ou donné au même instant par un autre poste).
                $deja = DB::table('numeros_dossier')->where('etablissement_id', $etablissement->id)
                    ->where('dossier_id', $dossier->id)->value('numero');
                if (is_string($deja)) {
                    return $deja;
                }
            }
        }

        throw new Refus('Impossible de donner un numéro de dossier : réessayez.');
    }

    /**
     * @return array<string, mixed>
     */
    public function identiteAccueil(Dossier $dossier): array
    {
        return [
            'uuid' => $dossier->uuid,
            'nom' => $dossier->nom,
            'telephone' => $dossier->telephone,
            'adresse' => $dossier->adresse,
            'sexe' => $dossier->sexe,
            'date_naissance' => $dossier->date_naissance?->toDateString(),
            'naissance_estimee' => $dossier->naissance_estimee,
            'age' => $dossier->date_naissance?->age,
            'a_un_compte' => $dossier->personne_id !== null,
        ];
    }

    public function pourPersonne(Personne $personne): Dossier
    {
        return Dossier::firstOrCreate(['personne_id' => $personne->id], [
            'nom' => $personne->nom,
            'telephone' => $personne->telephone,
            'date_naissance' => $personne->date_naissance,
            'sexe' => $personne->sexe,
        ]);
    }

    /** L'établissement peut-il voir le dossier ? (le sien, ou un patient qu'il a reçu) */
    public function connu(Dossier $dossier, Etablissement $etablissement): bool
    {
        return $dossier->etablissement_id === $etablissement->id
            || Visite::where('dossier_id', $dossier->id)->where('etablissement_id', $etablissement->id)->exists()
            || $this->accesHistorique($dossier, $etablissement);
    }

    /** L'historique tenu ailleurs : seulement avec un accord en cours. */
    public function accesHistorique(Dossier $dossier, Etablissement $etablissement): bool
    {
        return AccesDossier::where('dossier_id', $dossier->id)
            ->where('etablissement_id', $etablissement->id)
            ->whereNull('revoque_at')
            ->where('expire_at', '>', now())
            ->exists();
    }

    /**
     * Ouvre le dossier pour un membre : son établissement voit ses propres
     * consultations, et celles d'ailleurs s'il a l'accord. Tracé.
     *
     * @return array<string, mixed>
     */
    public function lire(Dossier $dossier, User $membre): array
    {
        $etablissement = Etablissement::findOrFail($membre->etablissement_id);
        $acces = $this->accesHistorique($dossier, $etablissement);

        $examens = ExamenClinique::with('etablissement', 'praticien')->where('dossier_id', $dossier->id)->latest()->get();
        $visibles = $examens->filter(fn (ExamenClinique $e): bool => $acces || $e->etablissement_id === $etablissement->id);

        LectureDossier::create([
            'dossier_id' => $dossier->id,
            'etablissement_id' => $etablissement->id,
            'membre_id' => $membre->id,
            'historique' => $acces,
            'lu_at' => now(),
        ]);

        return [
            ...$this->identite($dossier),
            'acces_historique' => $acces,
            'consultations_ailleurs' => $acces ? 0 : $examens->count() - $visibles->count(),
            'peut_demander_acces' => $dossier->personne_id !== null,
            'consultations' => $visibles->values()->map(fn (ExamenClinique $e): array => $this->examen($e, $etablissement)),
            // Les analyses demandées ici (même en cours), et celles d'ailleurs avec l'accord.
            'analyses' => DemandeExamen::where('dossier_id', $dossier->id)->where('statut', '!=', 'annulee')
                ->where(fn ($q) => $q->where('etablissement_id', $etablissement->id)
                    ->when($acces, fn ($q) => $q->orWhere('statut', 'validee')))
                ->latest()->get()
                ->map(fn (DemandeExamen $d): array => $this->resultats($d)),
        ];
    }

    /**
     * Le patient, présent, donne son accord de vive voix. Seulement s'il a une
     * visite ouverte aujourd'hui ici ; c'est tracé, et il le voit dans son app.
     */
    public function accorderSurPlace(Dossier $dossier, Etablissement $etablissement): AccesDossier
    {
        $present = Visite::where('dossier_id', $dossier->id)->where('etablissement_id', $etablissement->id)
            ->where('statut', 'ouverte')->where('ouverte_at', '>=', now()->subHours(16))->exists();
        if (! $present) {
            throw new Refus('Le patient doit être là : ouvrez d’abord sa visite d’aujourd’hui.');
        }

        return $this->accorder($dossier, $etablissement, 'presence');
    }

    /** Le patient reçoit un code par SMS, qu'il dicte au soignant. */
    public function demanderCode(Dossier $dossier): void
    {
        if (! config('sante.sms.actif')) {
            throw new Refus('Les SMS ne partent pas ici : scannez le QR du patient, ou notez son accord sur place.');
        }
        $telephone = $this->telephoneDuPatient($dossier);
        $this->codes->envoyer($telephone, CodesVerification::DOSSIER);
    }

    public function accorderParCode(Dossier $dossier, Etablissement $etablissement, string $code): AccesDossier
    {
        if (! $this->codes->verifier($this->telephoneDuPatient($dossier), CodesVerification::DOSSIER, $code)) {
            throw new Refus('Code incorrect ou expiré.');
        }

        return $this->accorder($dossier, $etablissement, 'sms');
    }

    /** Le soignant scanne le QR d'accord affiché par l'app du patient. */
    public function accorderParQr(string $qr, Etablissement $etablissement): Dossier
    {
        $lu = Qr::lire($qr);
        if ($lu === null || $lu[0] !== 'accord') {
            throw new Refus('Ce QR n’est pas un accord d’accès au dossier.');
        }
        $personne = Personne::where('accord_jeton', $lu[1])->where('accord_expire_at', '>', now())->first();
        if ($personne === null) {
            throw new Refus('Accord expiré : demandez au patient de l’afficher à nouveau.');
        }
        $personne->update(['accord_jeton' => null, 'accord_expire_at' => null]);
        $dossier = $this->pourPersonne($personne);
        $this->accorder($dossier, $etablissement, 'qr');

        return $dossier;
    }

    /**
     * Le QR d'accord que la personne montre au soignant (usage unique, 10 min).
     *
     * @return array{qr: string, expire_at: string}
     */
    public function genererAccord(Personne $personne): array
    {
        $jeton = Str::random(32);
        $personne->update(['accord_jeton' => $jeton, 'accord_expire_at' => now()->addMinutes(self::VALIDITE_QR_MIN)]);

        return ['qr' => Qr::creer('accord', $jeton), 'expire_at' => now()->addMinutes(self::VALIDITE_QR_MIN)->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    public function identite(Dossier $dossier): array
    {
        return [
            'uuid' => $dossier->uuid,
            'nom' => $dossier->nom,
            'telephone' => $dossier->telephone,
            'adresse' => $dossier->adresse,
            'date_naissance' => $dossier->date_naissance?->toDateString(),
            'naissance_estimee' => $dossier->naissance_estimee,
            'age' => $dossier->date_naissance?->age,
            'sexe' => $dossier->sexe,
            'groupe_sanguin' => $dossier->groupe_sanguin,
            'allergies' => $dossier->allergies,
            'antecedents' => $dossier->antecedents,
            'traitements' => $dossier->traitements,
            'a_un_compte' => $dossier->personne_id !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function examen(ExamenClinique $e, ?Etablissement $vu = null): array
    {
        $e->loadMissing('etablissement', 'praticien');

        return [
            'uuid' => $e->uuid,
            'date' => $e->created_at?->toIso8601String(),
            'etablissement' => $e->etablissement->nom,
            'ici' => $vu !== null && $e->etablissement_id === $vu->id,
            'praticien' => $e->praticien?->getAttribute('name'),
            'motif' => $e->motif,
            'constantes' => $e->constantes ?? (object) [],
            'examen' => $e->examen,
            'diagnostic' => $e->diagnostic,
            'cim10' => $e->cim10,
            'conclusion' => $e->conclusion,
            'prochain_rdv' => $e->prochain_rdv?->toDateString(),
        ];
    }

    /**
     * Une demande d'analyses vue du dossier : les résultats seulement s'ils
     * sont validés.
     *
     * @return array<string, mixed>
     */
    public function resultats(DemandeExamen $d): array
    {
        $d->loadMissing('lignes', 'etablissement');
        $valide = $d->statut === 'validee';

        return [
            'uuid' => $d->uuid,
            'statut' => $d->statut,
            'date' => $d->created_at?->toIso8601String(),
            'etablissement' => $d->etablissement->nom,
            'lignes' => $d->lignes->map(fn (DemandeAnalyse $l): array => [
                'nom' => $l->nom,
                'unite' => $l->unite,
                'normale' => $l->normale,
                'resultat' => $valide ? $l->resultat : null,
                'anormal' => $valide ? $l->anormal : null,
                'commentaire' => $valide ? $l->commentaire : null,
            ])->values(),
        ];
    }

    private function accorder(Dossier $dossier, Etablissement $etablissement, string $par): AccesDossier
    {
        return AccesDossier::create([
            'dossier_id' => $dossier->id,
            'etablissement_id' => $etablissement->id,
            'par' => $par,
            'expire_at' => now()->addDays(self::DUREE_ACCES_JOURS),
        ]);
    }

    private function telephoneDuPatient(Dossier $dossier): string
    {
        $personne = $dossier->personne_id !== null ? Personne::find($dossier->personne_id) : null;
        if ($personne === null) {
            throw new Refus('Ce patient n’a pas de compte : son dossier reste dans cet établissement.');
        }

        return $personne->telephone;
    }
}
