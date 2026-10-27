<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Appareil;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un écran ou une borne appairé : en-tête `X-Appareil: <jeton>`.
 */
class AuthAppareil
{
    public function handle(Request $request, Closure $next): Response
    {
        $jeton = $request->header('X-Appareil');
        $appareil = is_string($jeton) && $jeton !== ''
            ? Appareil::where('jeton', hash('sha256', $jeton))->whereNull('revoque_at')->first()
            : null;

        if ($appareil === null) {
            return response()->json(['message' => 'Appareil non reconnu.', 'code' => 'appareil_inconnu'], 401);
        }

        // Une écriture par minute au plus : les écrans interrogent souvent.
        if ($appareil->dernier_contact_at === null || $appareil->dernier_contact_at->lt(now()->subMinute())) {
            $appareil->update(['dernier_contact_at' => now()]);
        }

        $request->attributes->set('appareil', $appareil);

        return $next($request);
    }
}
