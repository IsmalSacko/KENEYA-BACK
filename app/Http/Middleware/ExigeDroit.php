<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('droit:file.appeler')` : refuse (403) le membre qui n'a pas
 * ce droit dans son établissement, ou dont l'établissement n'a pas le module.
 * Plusieurs droits au choix : `droit:file.emettre|file.appeler`.
 */
class ExigeDroit
{
    public function handle(Request $request, Closure $next, string $droit): Response
    {
        $membre = $request->user();

        $accepte = $membre instanceof User
            && array_filter(explode('|', $droit), static fn (string $d): bool => $membre->peut($d)) !== [];

        if (! $accepte) {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => 'Votre rôle ne permet pas cette action dans cet établissement.',
                'droit' => $droit,
            ], 403);
        }

        return $next($request);
    }
}
