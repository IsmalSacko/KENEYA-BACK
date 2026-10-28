<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La Console Ngoni (toutes les applications IsmaelDev) s'annonce avec la
 * clé secrète de Ngoni Santé (`SANTE_CLE_CONSOLE`). Sans clé configurée,
 * l'accès est fermé.
 */
class CleConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        $attendue = config('sante.console.cle');
        $recue = $request->bearerToken();

        if (! is_string($attendue) || $attendue === '' || ! is_string($recue) || ! hash_equals($attendue, $recue)) {
            return response()->json(['message' => 'Clé de console invalide.'], 401);
        }

        return $next($request);
    }
}
