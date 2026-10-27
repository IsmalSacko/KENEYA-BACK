<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddUserRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $etablissementUuid = $request->input('uuid');
        $userUuid = $request->input('user_uuid');

        // Idempotence : une inscription créée hors-ligne puis rejouée à la
        // reconnexion ne doit pas créer de doublon.
        if (is_string($userUuid)) {
            $existant = User::where('uuid', $userUuid)->first();
            if ($existant !== null) {
                $token = $this->jwtGuard()->login($existant);

                return response()->json([
                    'message' => 'Inscription déjà synchronisée',
                    'user' => $existant,
                    'etablissement' => $existant->etablissement,
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => $this->jwtGuard()->factory()->getTTL() * 60,
                ]);
            }
        }

        $etablissement = is_string($etablissementUuid)
            ? Etablissement::firstOrCreate(
                ['uuid' => $etablissementUuid],
                ['nom' => $request->nom_etablissement, 'type' => $request->type],
            )
            : Etablissement::create([
                'nom' => $request->nom_etablissement,
                'type' => $request->type,
            ]);

        /** @var array<string, mixed> $userData */
        $userData = [
            'etablissement_id' => $etablissement->id,
            'name' => $request->name,
            'telephone' => $request->telephone,
            // L'inscription publique crée l'admin de CET établissement uniquement
            // (jamais l'admin de l'instance : pas d'escalade de privilège).
            'role' => Role::AdminEtablissement->value,
            'pin' => $request->pin, // dejà hashé par le cast du modèle User
            'actif' => true,
        ];
        if (is_string($userUuid)) {
            $userData['uuid'] = $userUuid;
        }

        $user = User::create($userData);
        // Associer l'utilisateur à l'établissement
        $user->etablissements()->attach($etablissement->id);

        // Générer un token d'authentification pour l'utilisateur
        $token = $this->jwtGuard()->login($user);

        return response()->json([
            'message' => 'Inscription réussie',
            'user' => $user,
            'etablissement' => $etablissement,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtGuard()->factory()->getTTL() * 60,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $query = User::where('telephone', $request->telephone);

        $etablissementUuid = $request->input('etablissement_uuid');
        if (is_string($etablissementUuid)) {
            $etablissementId = Etablissement::where('uuid', $etablissementUuid)->value('id');
            $query->where('etablissement_id', is_numeric($etablissementId) ? (int) $etablissementId : 0);
        }

        $utilisateurs = $query->get();

        // Multi-tenant : même numéro dans plusieurs établissements → il faut
        // préciser lequel (le client renvoie alors etablissement_uuid).
        if ($utilisateurs->count() > 1) {
            $etablissements = Etablissement::whereIn('id', $utilisateurs->pluck('etablissement_id'))
                ->get(['uuid', 'nom', 'type']);

            return response()->json([
                'message' => 'Ce numéro existe dans plusieurs établissements. Précisez l\'établissement.',
                'code' => 'etablissement_requis',
                'etablissements' => $etablissements,
            ], 409);
        }

        $user = $utilisateurs->first();

        if ($user === null || ! Hash::check($request->string('pin')->toString(), (string) $user->pin)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        if (! $user->actif) {
            return response()->json(['message' => 'Votre compte est désactivé. Contactez l\'administrateur.'], 403);
        }

        $token = $this->jwtGuard()->login($user);

        return response()->json([
            'message' => 'Connexion réussie',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtGuard()->factory()->getTTL() * 60,
        ]);
    }

    public function addUser(AddUserRequest $request): JsonResponse
    {
        $auteur = $this->currentUser();

        if (! $auteur->canAdministerEtablissement()) {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut ajouter un utilisateur.'], 403);
        }

        // Anti-escalade : un admin d'établissement ne peut pas créer d'admin d'instance.
        if (! $auteur->isInstanceAdmin() && $request->role === Role::Admin->value) {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => "Vous ne pouvez pas attribuer le rôle d'administrateur de l'instance.",
            ], 403);
        }

        if (User::where('telephone', $request->telephone)->where('etablissement_id', $auteur->etablissement_id)->exists()) {
            return response()->json(['message' => 'Le numéro de téléphone est déjà utilisé.'], 409);
        }
        $user = User::create([
            'etablissement_id' => $this->currentUser()->etablissement_id,
            'name' => $request->name,
            'telephone' => $request->telephone,
            'role' => $request->role,
            'pin' => Hash::make($request->string('pin')->toString()),
            'actif' => true,
        ]);

        $user->etablissements()->attach($this->currentUser()->etablissement_id);

        $token = $this->jwtGuard()->login($user);

        return response()->json([
            'message' => 'Utilisateur ajouté avec succès',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $auteur = $this->currentUser();

        // Seul un administrateur (instance ou établissement) peut modifier un utilisateur.
        if (! $auteur->canAdministerEtablissement()) {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => 'Seul un administrateur peut modifier un utilisateur.',
            ], 403);
        }

        if ($user->etablissement_id !== $auteur->etablissement_id) {
            return response()->json([
                'message' => 'Utilisateur non trouvé ou hors établissement.',
            ], 404);
        }

        // Un admin d'établissement ne peut pas modifier un admin d'instance.
        if (! $auteur->isInstanceAdmin() && $user->isInstanceAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = $request->only(['name', 'actif']);
        if ($request->filled('pin')) {
            $data['pin'] = Hash::make($request->string('pin')->toString());
        }
        if ($request->filled('telephone')) {
            if (
                User::where('telephone', $request->telephone)
                    ->where('etablissement_id', $auteur->etablissement_id)
                    ->where('id', '!=', $user->id)
                    ->exists()
            ) {
                return response()->json(
                    [
                        'message' => 'Le numéro de téléphone est déjà utilisé par un autre utilisateur.',
                    ],
                    409
                );
            }
            $data['telephone'] = $request->telephone;
        }

        if ($request->filled('role')) {
            $nouveauRole = $request->string('role')->toString();

            // On ne peut pas changer son propre rôle (éviter de se verrouiller / s'auto-promouvoir).
            if ($user->id === $auteur->id) {
                return response()->json([
                    'message' => 'Accès refusé',
                    'reason' => 'Vous ne pouvez pas modifier votre propre rôle.',
                ], 403);
            }

            $rolesAutorises = $auteur->isInstanceAdmin()
                ? Role::values()
                : Role::assignableByEtablissementAdmin();

            if (! in_array($nouveauRole, $rolesAutorises, true)) {
                return response()->json([
                    'message' => 'Accès refusé',
                    'reason' => "Vous n'êtes pas autorisé à attribuer ce rôle.",
                ], 403);
            }
            $data['role'] = $nouveauRole;
        }

        $user->update($data);

        return response()->json(['message' => 'Utilisateur mis à jour', 'user' => $user->fresh()]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        $auteur = $this->currentUser();

        if (! $auteur->canAdministerEtablissement()) {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut supprimer un utilisateur.'], 403);
        }
        if ($user->etablissement_id !== $auteur->etablissement_id) {
            return response()->json(['message' => 'Utilisateur non trouvé ou n\'appartenant pas à votre établissement.'], 404);
        }
        // On ne se supprime pas soi-même ; un admin d'établissement ne supprime pas un admin d'instance.
        if ($user->id === $auteur->id) {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
        }
        if (! $auteur->isInstanceAdmin() && $user->isInstanceAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé avec succès.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->jwtGuard()->logout();

        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $membre = $this->currentUser();

        return response()->json([
            'user' => $membre,
            'etablissement' => $membre->etablissement()->first(),
            'permissions' => $membre->permissions(),
        ]);
    }

    public function refresh(): JsonResponse
    {
        $token = $this->jwtGuard()->refresh();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtGuard()->factory()->getTTL() * 60,
        ]);
    }
}
