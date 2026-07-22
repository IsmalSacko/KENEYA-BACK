<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddUserRequest;
use App\Http\Requests\LoginRequest;

use App\Http\Requests\RegisterRequest;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;


class AuthController extends Controller
{

    public function register(RegisterRequest $request)
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
            'pin' => $request->pin, // dejà hashé par le mutator du modèle User
            'actif' => true,
        ]);
        // Associer l'utilisateur à l'établissement
        $user->etablissements()->attach($etablissement->id);

        // Générer un token d'authentification pour l'utilisateur
        $token = $user->createToken('auth_token')->plainTextToken;


        return response()->json([
            'message' => 'Inscription réussie',
            'user' => $user,
            'etablissement' => $etablissement,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('telephone', $request->telephone)->first();

        if (!$user || !Hash::check($request->pin, $user->pin)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        if (!$user->actif) {
            return response()->json(['message' => 'Votre compte est désactivé. Contactez l\'administrateur.'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function addUser(AddUserRequest $request)
    {
        //$this->authorize('create', User::class);
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut ajouter un utilisateur.'], 403);
        }
        if (User::where('telephone', $request->telephone)->where('etablissement_id', $request->user()->etablissement_id)->exists()) {
            return response()->json(['message' => 'Le numéro de téléphone est déjà utilisé.'], 409);
        }
        $user = User::create([
            'etablissement_id' => $request->user()->etablissement_id,
            'name' => $request->name,
            'telephone' => $request->telephone,
            'role' => $request->role,
            'pin' => Hash::make($request->pin),
            'actif' => true,
        ]);

        $user->etablissements()->attach($request->user()->etablissement_id); // Associer l'utilisateur à l'établissement de l'administrateur

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Utilisateur ajouté avec succès',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }


    public function update(Request $request)
    {
        $user = User::find($request->id);

        if (!$user || $user->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json([
                'message' => 'Utilisateur non trouvé ou hors établissement.'
            ], 404);
        }

        $data = $request->only(['name', 'role', 'actif']);
        if ($request->filled('pin')) {
            $data['pin'] = Hash::make($request->pin);
        }
        if ($request->filled('telephone')) {
            if (
                User::where('telephone', $request->telephone)
                    ->where('etablissement_id', $request->user()->etablissement_id)
                    ->where('id', '!=', $user->id)
                    ->exists()
            ) {
                return response()->json(
                    [
                        'message' => 'Le numéro de téléphone est déjà utilisé par un autre utilisateur.'
                    ]
                    ,
                    409
                );
            }
            $data['telephone'] = $request->telephone;
        }
        if ($request->filled('role') && $request->user()->role != 'admin') {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => 'Seul un administrateur peut modifier le rôle d\'un utilisateur.'
            ], 403);
        }

        $user->update($data);
        return response()->json(['message' => 'Utilisateur mis à jour', 'user' => $user->fresh(),]);
    }



    public function deleteUser(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Accès refusé', 'reason' => 'Seul un administrateur peut supprimer un utilisateur.'], 403);
        }
        $user = User::find($request->id);
        if (!$user || $user->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Utilisateur non trouvé ou n\'appartenant pas à votre établissement.'], 404);
        }
        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé avec succès.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }

}
