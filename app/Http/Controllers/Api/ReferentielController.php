<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pays;
use App\Sante\Module;
use App\Sante\Profil;
use Illuminate\Http\JsonResponse;

/**
 * Données de référence publiques : pays, profils d'établissement, modules.
 * L'app les garde en cache pour fonctionner hors ligne.
 *
 * @tag Référentiel
 */
class ReferentielController extends Controller
{
    /** Ce que l'app doit savoir du serveur : les SMS partent-ils vraiment ? */
    public function options(): JsonResponse
    {
        return response()->json(['sms' => (bool) config('sante.sms.actif')]);
    }

    public function pays(): JsonResponse
    {
        return response()->json([
            'data' => Pays::where('actif', true)->orderBy('ordre')->get(),
            'defaut' => Pays::DEFAUT,
        ]);
    }

    public function profils(): JsonResponse
    {
        return response()->json([
            'data' => array_map(static fn (Profil $p): array => $p->toArray(), Profil::cases()),
            'modules' => array_map(
                static fn (Module $m): array => ['code' => $m->value, 'label' => $m->label()],
                Module::cases(),
            ),
        ]);
    }
}
