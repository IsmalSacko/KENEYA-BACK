<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Sante\Apple\AchatsApple;
use App\Sante\Apple\JwsInvalide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * App Store Server Notifications V2 : Apple annonce achats, renouvellements,
 * fins et remboursements. Seul un JWS signé par Apple est lu ; signature
 * invalide : 400 (Apple réessaie), sinon 200, même pour un type ignoré.
 */
class AppleNotificationController extends Controller
{
    public function __invoke(Request $request, AchatsApple $apple): JsonResponse
    {
        $signe = $request->input('signedPayload');
        if (! is_string($signe) || $signe === '') {
            return response()->json(['message' => 'signedPayload attendu.'], 400);
        }

        try {
            $apple->notifier($signe);
        } catch (JwsInvalide $e) {
            Log::warning('App Store : notification refusée', ['erreur' => $e->getMessage()]);

            return response()->json(['message' => 'Signature invalide.'], 400);
        }

        return response()->json(['message' => 'Reçu.']);
    }
}
