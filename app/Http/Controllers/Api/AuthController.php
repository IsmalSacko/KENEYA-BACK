<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddUserRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    /**
     * Cookie HttpOnly qui transporte le JWT pour la navigation web.
     * (Le token est aussi renvoyé dans le corps pour les clients Bearer.)
     */
    private function tokenCookie(string $token): Cookie
    {
        /** @var string|null $domain */
        $domain = config('session.domain');

        return Cookie::create(
            name: 'access_token',
            value: $token,
            expire: now()->addMinutes(Config::integer('jwt.ttl')),
            path: '/',
            domain: $domain,
            secure: app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $etablissement = Etablissement::create([
            'nom' => $request->nom_etablissement,
            'type' => $request->type,
        ]);

        $user = User::create([
            'etablissement_id' => $etablissement->id,
            'name' => $request->name,
            'telephone' => $request->telephone,
            'role' => 'admin',
            'pin' => $request->pin, // dejà hashé par le cast du modèle User
            'actif' => true,
        ]);
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
        ], 201)->withCookie($this->tokenCookie($token));
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('telephone', $request->telephone)->first();

        if (! $user || ! Hash::check($request->string('pin')->toString(), $user->pin)) {
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
        ])->withCookie($this->tokenCookie($token));
    }

    public function addUser(AddUserRequest $request): JsonResponse
    {
        if ($this->currentUser()->role !== 'admin') {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut ajouter un utilisateur.'], 403);
        }
        if (User::where('telephone', $request->telephone)->where('etablissement_id', $this->currentUser()->etablissement_id)->exists()) {
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
        if ($user->etablissement_id !== $this->currentUser()->etablissement_id) {
            return response()->json([
                'message' => 'Utilisateur non trouvé ou hors établissement.',
            ], 404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->only(['name', 'role', 'actif']);
        if ($request->filled('pin')) {
            $data['pin'] = Hash::make($request->string('pin')->toString());
        }
        if ($request->filled('telephone')) {
            if (
                User::where('telephone', $request->telephone)
                    ->where('etablissement_id', $this->currentUser()->etablissement_id)
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
        if ($request->filled('role') && $this->currentUser()->role != 'admin') {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => 'Seul un administrateur peut modifier le rôle d\'un utilisateur.',
            ], 403);
        }

        $user->update($data);

        return response()->json(['message' => 'Utilisateur mis à jour', 'user' => $user->fresh()]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($this->currentUser()->role !== 'admin') {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut supprimer un utilisateur.'], 403);
        }
        if ($user->etablissement_id !== $this->currentUser()->etablissement_id) {
            return response()->json(['message' => 'Utilisateur non trouvé ou n\'appartenant pas à votre établissement.'], 404);
        }
        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé avec succès.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->jwtGuard()->logout();

        /** @var string|null $domain */
        $domain = config('session.domain');

        return response()->json([
            'message' => 'Déconnexion réussie',
        ])->withCookie(cookie()->forget('access_token', '/', $domain));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->currentUser()]);
    }

    public function refresh(): JsonResponse
    {
        $token = $this->jwtGuard()->refresh();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtGuard()->factory()->getTTL() * 60,
        ])->withCookie($this->tokenCookie($token));
    }
}
