<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Etablissement;
use App\Models\User;
use App\Sante\Abonnements\Abonnements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('fonction:rapport')` : refuse (403) quand la formule de
 * l'établissement n'inclut pas cette fonction, ou que l'abonnement est échu.
 */
class ExigeFonction
{
    public function __construct(private readonly Abonnements $abonnements) {}

    public function handle(Request $request, Closure $next, string $fonction): Response
    {
        $membre = $request->user();
        $etab = $membre instanceof User ? Etablissement::find($membre->etablissement_id) : null;

        if ($etab !== null && ! $this->abonnements->aFonction($etab, $fonction)) {
            return response()->json([
                'message' => 'Non inclus dans votre formule',
                'reason' => 'Cette fonction demande un abonnement en cours à la formule Pro ou Hôpital.',
                'code' => 'FONCTION_NON_INCLUSE',
                'fonction' => $fonction,
            ], 403);
        }

        return $next($request);
    }
}
