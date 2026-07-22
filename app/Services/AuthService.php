<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\JWTGuard;

class AuthService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, etablissement: Etablissement, token: string}
     */
    public function register(array $data): array
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

        $token = $this->jwtGuard()->login($user);

        return compact('user', 'etablissement', 'token');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, token: string}|false|null
     */
    public function login(array $data): array|false|null
    {
        $user = User::where('telephone', $data['telephone'])->first();
        $pin = is_string($data['pin']) ? $data['pin'] : '';

        if (! $user || ! Hash::check($pin, $user->pin)) {
            return null;
        }

        if (! $user->actif) {
            return false;
        }

        $token = $this->jwtGuard()->login($user);

        return compact('user', 'token');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addUser(array $data, User $authUser): User
    {
        return User::create([
            'etablissement_id' => $authUser->etablissement_id,
            'name' => $data['name'],
            'telephone' => $data['telephone'],
            'role' => $data['role'],
            'pin' => $data['pin'],
            'actif' => false,
        ]);
    }

    public function deleteUser(User $user): void
    {
        $user->delete();
    }

    public function logout(User $user): void
    {
        $this->jwtGuard()->logout();
    }

    private function jwtGuard(): JWTGuard
    {
        $guard = Auth::guard('api');

        if (! $guard instanceof JWTGuard) {
            throw new \RuntimeException('Le guard "api" doit utiliser le driver jwt.');
        }

        return $guard;
    }
}
