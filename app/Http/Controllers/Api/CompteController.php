<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compte\CodeRequest;
use App\Http\Requests\Compte\ConnexionRequest;
use App\Http\Requests\Compte\CreerEtablissementRequest;
use App\Http\Requests\Compte\InscriptionRequest;
use App\Http\Requests\Compte\ModifierCompteRequest;
use App\Http\Requests\Compte\PinOublieRequest;
use App\Models\Etablissement;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\User;
use App\Sante\CodesVerification;
use App\Sante\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le compte Ngoni Santé : une personne, son espace « Ma santé » et les
 * espaces pro des établissements où elle travaille.
 *
 * Deux jetons : celui de la personne (guard `personne`, routes /compte) et,
 * quand elle ouvre un espace pro, celui du membre (guard `api`, toutes les
 * routes pro existantes, inchangées).
 *
 * @tag Compte
 */
class CompteController extends Controller
{
    /** Échecs de PIN tolérés par numéro avant blocage temporaire. */
    private const ESSAIS_PIN = 5;

    public function __construct(private readonly CodesVerification $codes) {}

    /**
     * Envoie un code SMS. La réponse est la même que le numéro ait un compte
     * ou non : elle ne révèle pas qui est inscrit.
     */
    public function code(CodeRequest $request): JsonResponse
    {
        $telephone = $this->telephone($request->string('pays_code')->toString(), $request->string('telephone')->toString());
        $objet = $request->string('objet')->toString();
        $existe = Personne::where('telephone', $telephone)->exists();

        if (! config('sante.sms.actif')) {
            return response()->json(['message' => $objet === CodesVerification::PIN
                ? 'Les SMS ne partent pas encore : demandez un nouveau PIN à l’accueil de votre centre de santé.'
                : 'Les SMS ne partent pas encore : l’inscription se fait sans code.'], 422);
        }

        if (($objet === CodesVerification::INSCRIPTION && ! $existe) || ($objet === CodesVerification::PIN && $existe)) {
            $this->codes->envoyer($telephone, $objet);
        }

        return response()->json(['message' => 'Si ce numéro est valable, un code vient de lui être envoyé.'], 202);
    }

    public function inscription(InscriptionRequest $request): JsonResponse
    {
        $paysCode = $request->string('pays_code')->toString();
        $telephone = $this->telephone($paysCode, $request->string('telephone')->toString());

        if (Personne::withTrashed()->where('telephone', $telephone)->exists()) {
            return response()->json([
                'message' => 'Ce numéro a déjà un compte. Connectez-vous.',
                'code' => 'compte_existant',
            ], 409);
        }

        $verifie = false;
        if ($request->filled('code')) {
            $verifie = $this->codes->verifier($telephone, CodesVerification::INSCRIPTION, $request->string('code')->toString());
            if (! $verifie) {
                return response()->json(['message' => 'Code incorrect ou expiré.', 'code' => 'code_invalide'], 422);
            }
        } elseif (config('sante.verification_telephone') === true) {
            return response()->json(['message' => 'Le code reçu par SMS est requis.', 'code' => 'code_requis'], 422);
        }

        $personne = Personne::create([
            'nom' => $request->string('nom')->toString(),
            'pays_code' => $paysCode,
            'telephone' => $telephone,
            'pin' => $request->string('pin')->toString(),
            'telephone_verifie_at' => $verifie ? now() : null,
            'actif' => true,
        ]);

        return $this->reponsePersonne($personne, 'Compte créé.', 201);
    }

    public function connexion(ConnexionRequest $request): JsonResponse
    {
        $telephone = $this->telephone($request->string('pays_code')->toString(), $request->string('telephone')->toString());
        $cle = 'pin:'.$telephone;

        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_PIN)) {
            return response()->json([
                'message' => 'Trop d’essais. Réessayez dans '.max(1, intdiv(RateLimiter::availableIn($cle), 60)).' min.',
                'code' => 'trop_essais',
            ], 429);
        }

        $personne = Personne::where('telephone', $telephone)->first();

        if ($personne === null || ! Hash::check($request->string('pin')->toString(), $personne->pin)) {
            RateLimiter::hit($cle, 15 * 60);

            return response()->json(['message' => 'Numéro ou PIN incorrect.'], 401);
        }

        RateLimiter::clear($cle);

        if (! $personne->actif) {
            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }

        return $this->reponsePersonne($personne, 'Connexion réussie.');
    }

    public function pinOublie(PinOublieRequest $request): JsonResponse
    {
        $telephone = $this->telephone($request->string('pays_code')->toString(), $request->string('telephone')->toString());
        $personne = Personne::where('telephone', $telephone)->first();

        if ($personne === null || ! $this->codes->verifier($telephone, CodesVerification::PIN, $request->string('code')->toString())) {
            return response()->json(['message' => 'Code incorrect ou expiré.', 'code' => 'code_invalide'], 422);
        }

        $personne->update([
            'pin' => $request->string('pin')->toString(),
            'telephone_verifie_at' => $personne->telephone_verifie_at ?? now(),
        ]);
        RateLimiter::clear('pin:'.$telephone);

        return $this->reponsePersonne($personne, 'PIN modifié.');
    }

    public function moi(): JsonResponse
    {
        $personne = $this->currentPersonne();

        return response()->json([
            'personne' => $personne,
            'espaces' => $this->espaces($personne),
        ]);
    }

    public function modifier(ModifierCompteRequest $request): JsonResponse
    {
        $personne = $this->currentPersonne();
        $personne->update($request->validated());

        return response()->json(['message' => 'Compte mis à jour.', 'personne' => $personne->fresh()]);
    }

    /**
     * Ouvre l'espace pro d'un établissement : renvoie le jeton du membre,
     * celui qu'attendent toutes les routes pro.
     */
    public function ouvrirEspace(string $membreUuid): JsonResponse
    {
        $personne = $this->currentPersonne();
        $membre = $personne->membres()->where('uuid', $membreUuid)->with('etablissement')->first();

        if ($membre === null || $membre->etablissement === null) {
            return response()->json(['message' => 'Espace introuvable.'], 404);
        }
        if (! $membre->actif) {
            return response()->json(['message' => 'Votre accès à cet établissement est désactivé.'], 403);
        }
        if ($membre->etablissement->getAttribute('actif') === false) {
            return response()->json(['message' => 'Cet établissement est désactivé.'], 403);
        }

        return $this->reponseMembre($membre, 'Espace ouvert.');
    }

    /**
     * La personne crée son établissement et en devient l'admin. Rejouable
     * hors ligne : mêmes uuid, même résultat.
     */
    public function creerEtablissement(CreerEtablissementRequest $request): JsonResponse
    {
        $personne = $this->currentPersonne();
        $membreUuid = $request->input('membre_uuid');

        if (is_string($membreUuid)) {
            $dejaCree = $personne->membres()->where('uuid', $membreUuid)->first();
            if ($dejaCree !== null) {
                return $this->reponseMembre($dejaCree, 'Établissement déjà créé.');
            }
        }

        $paysCode = $request->input('pays_code', $personne->pays_code);
        $pays = Pays::findOrFail(is_string($paysCode) ? $paysCode : $personne->pays_code);

        $membre = DB::transaction(function () use ($request, $personne, $pays, $membreUuid): User {
            $donnees = [
                'nom' => $request->string('nom')->toString(),
                'profil' => $request->string('profil')->toString(),
                'pays_code' => $pays->code,
                'devise' => $pays->devise,
                'telephone' => $request->input('telephone'),
                'adresse' => $request->input('adresse'),
                'actif' => true,
            ];
            $uuid = $request->input('uuid');
            $etablissement = is_string($uuid)
                ? Etablissement::firstOrCreate(['uuid' => $uuid], $donnees)
                : Etablissement::create($donnees);

            $membre = new User([
                'etablissement_id' => $etablissement->id,
                'name' => $personne->nom,
                'telephone' => $personne->telephone,
                'role' => Role::AdminEtablissement->value,
                'actif' => true,
            ]);
            if (is_string($membreUuid)) {
                $membre->setAttribute('uuid', $membreUuid);
            }
            $membre->setAttribute('personne_id', $personne->id);
            // Le membre garde le PIN de la personne (connexion 3.x, hors ligne).
            $membre->setRawAttributes(array_merge($membre->getAttributes(), ['pin' => $personne->pin]));
            $membre->save();
            $membre->etablissements()->attach($etablissement->id);

            return $membre;
        });

        return $this->reponseMembre($membre, 'Établissement créé.', 201);
    }

    public function refresh(): JsonResponse
    {
        $guard = $this->personneGuard();

        return response()->json([
            'access_token' => $guard->refresh(),
            'token_type' => 'Bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
        ]);
    }

    public function deconnexion(): JsonResponse
    {
        $this->personneGuard()->logout();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    private function telephone(string $paysCode, string $saisi): string
    {
        return Telephone::normaliser($saisi, Pays::findOrFail($paysCode));
    }

    private function reponsePersonne(Personne $personne, string $message, int $statut = 200): JsonResponse
    {
        $guard = $this->personneGuard();

        return response()->json([
            'message' => $message,
            'personne' => $personne->fresh(),
            'espaces' => $this->espaces($personne),
            'access_token' => $guard->login($personne),
            'token_type' => 'Bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
        ], $statut);
    }

    private function reponseMembre(User $membre, string $message, int $statut = 200): JsonResponse
    {
        $guard = $this->jwtGuard();

        return response()->json([
            'message' => $message,
            'user' => $membre->fresh(),
            'etablissement' => $membre->etablissement()->first(),
            'permissions' => $membre->permissions(),
            'access_token' => $guard->login($membre),
            'token_type' => 'Bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
        ], $statut);
    }

    /**
     * Les espaces pro de la personne : un par établissement où elle est membre.
     *
     * @return list<array<string, mixed>>
     */
    private function espaces(Personne $personne): array
    {
        $espaces = [];
        foreach ($personne->membres()->with('etablissement')->get() as $membre) {
            $etablissement = $membre->etablissement;
            if ($etablissement === null) {
                continue;
            }
            $role = Role::tryFrom((string) $membre->role);
            $espaces[] = [
                'membre_uuid' => $membre->uuid,
                'role' => $membre->role,
                'role_label' => $role?->label() ?? $membre->role,
                'actif' => (bool) $membre->actif,
                'etablissement' => [
                    'uuid' => $etablissement->uuid,
                    'nom' => $etablissement->nom,
                    'profil' => $etablissement->profil,
                    'modules' => $etablissement->modules,
                    'pays_code' => $etablissement->pays_code,
                    'devise' => $etablissement->devise,
                    'actif' => (bool) $etablissement->getAttribute('actif'),
                ],
            ];
        }

        return $espaces;
    }
}
