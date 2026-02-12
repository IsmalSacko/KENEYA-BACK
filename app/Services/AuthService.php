<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function register(array $data)
    {
        $etablissement = Etablissement::create([
            'nom' => $data['nom_etablissement'],
            'type' => $data['type'],
        ]);

        $user = User::create([
            'etablissement_id' => $etablissement->id,
            'name' => $data['name'],
            'telephone' => $data['telephone'],
            'role' => 'admin',
            'pin' => $data['pin'],
            'actif' => true,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return compact('user', 'etablissement', 'token'); // Retourne l'utilisateur, l'établissement et le token
    }

    public function login(array $data)
    {
        $user = User::where('telephone', $data['telephone'])->first();

        if (!$user || !Hash::check($data['pin'], $user->pin)) {
            return null;
        }

        if (!$user->actif) {
            return false;
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return compact('user', 'token'); // Retourne l'utilisateur et le token
    }

    public function addUser(array $data, User $authUser)
    {

        $user = User::create([
            'etablissement_id' => $authUser->etablissement_id,
            'name' => $data['name'],
            'telephone' => $data['telephone'],
            'role' => $data['role'],
            'pin' => $data['pin'],
            'actif' => false
        ]);

        return $user; // Retourne l'utilisateur créé
    }




    public function deleteUser(User $user)
    {
        $user->delete();
    }

    public function logout(User $user)
    {
        $user->tokens()->delete();
    }
}
