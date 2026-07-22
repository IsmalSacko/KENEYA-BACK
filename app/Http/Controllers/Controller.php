<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\JWTGuard;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Utilisateur authentifié, typé App\Models\User (routes protégées par auth:api).
     * Centralise l'accès pour rester conforme à PHPStan niveau 9.
     */
    protected function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401, 'Non authentifié.');
        }

        return $user;
    }

    /**
     * Guard JWT typé (login()/refresh()/factory() correctement reconnus par PHPStan).
     */
    protected function jwtGuard(): JWTGuard
    {
        $guard = auth('api');

        if (! $guard instanceof JWTGuard) {
            throw new \RuntimeException('Le guard "api" doit utiliser le driver jwt.');
        }

        return $guard;
    }
}
