<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Personne;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La console Ngoni Santé : réservée aux personnes marquées administrateurs
 * de la plateforme (`php artisan sante:admin-plateforme {telephone}`).
 */
class EstAdminPlateforme
{
    public function handle(Request $request, Closure $next): Response
    {
        $personne = $request->user('personne');
        if (! $personne instanceof Personne || ! $personne->admin_plateforme) {
            return response()->json(['message' => 'Réservé à l’équipe Ngoni Santé.'], 403);
        }

        return $next($request);
    }
}
