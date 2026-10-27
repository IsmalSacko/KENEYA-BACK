<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appareil;
use App\Models\Service;
use App\Sante\File\FileAttente;
use App\Sante\File\Priorite;
use App\Sante\File\ServicesParDefaut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Écrans de salle d'attente et bornes d'accueil : appairés par un code (ou
 * son QR) créé dans l'espace pro, sans compte personnel.
 *
 * @tag File d'attente
 */
class AppareilController extends Controller
{
    /** Durée de validité d'un code d'appairage. */
    private const VALIDITE_MIN = 10;

    public function __construct(private readonly FileAttente $file) {}

    // ------------------------------------------------------------ côté équipe

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Appareil::where('etablissement_id', $this->currentUser()->etablissement_id)
                ->whereNull('revoque_at')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'mode' => ['required', Rule::in([Appareil::ECRAN, Appareil::BORNE])],
            'nom' => 'required|string|max:60',
            'services' => 'sometimes|nullable|array',
            'services.*' => 'uuid',
        ]);

        $appareil = Appareil::create([
            'etablissement_id' => $this->currentUser()->etablissement_id,
            'mode' => $donnees['mode'],
            'nom' => $donnees['nom'],
            'services' => $donnees['services'] ?? null,
        ]);

        return response()->json($this->nouveauCode($appareil), 201);
    }

    public function code(string $uuid): JsonResponse
    {
        return response()->json($this->nouveauCode($this->appareil($uuid)));
    }

    public function revoquer(string $uuid): JsonResponse
    {
        $this->appareil($uuid)->update(['revoque_at' => now(), 'jeton' => null, 'code_appairage' => null]);

        return response()->json(['message' => 'Appareil retiré.']);
    }

    // ------------------------------------------------------------ côté appareil

    /** L'écran ou la borne saisit (ou scanne) le code : il reçoit son jeton. */
    public function appairer(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => 'required|string|size:6'])['code'];
        $appareil = Appareil::where('code_appairage', $code)
            ->where('code_expire_at', '>', now())
            ->whereNull('revoque_at')
            ->first();

        if ($appareil === null) {
            return response()->json(['message' => 'Code inconnu ou expiré.'], 422);
        }

        $jeton = Str::random(48);
        $appareil->update([
            'jeton' => hash('sha256', $jeton),
            'code_appairage' => null,
            'code_expire_at' => null,
            'appaire_at' => now(),
            'dernier_contact_at' => now(),
        ]);

        return response()->json([
            'jeton' => $jeton,
            'appareil' => $appareil->fresh(),
            'etablissement' => [
                'uuid' => $appareil->etablissement->uuid,
                'nom' => $appareil->etablissement->nom,
            ],
        ]);
    }

    /** Ce qu'affiche l'écran (interrogé toutes les quelques secondes). */
    public function etat(Request $request): JsonResponse
    {
        $appareil = $this->appareilConnecte($request);

        return response()->json([
            ...$this->file->etat($appareil->etablissement, $appareil->services),
            'appareil' => ['uuid' => $appareil->uuid, 'nom' => $appareil->nom, 'mode' => $appareil->mode],
        ]);
    }

    /** Les services où la borne donne des tickets. */
    public function services(Request $request): JsonResponse
    {
        $appareil = $this->appareilConnecte($request);
        ServicesParDefaut::creerSiAucun($appareil->etablissement);

        return response()->json([
            'data' => $this->servicesDe($appareil)->get()->map(fn (Service $s): array => [
                'uuid' => $s->uuid,
                'nom' => $s->nom,
                'prefixe' => $s->prefixe,
            ]),
        ]);
    }

    /** La borne donne un ticket. */
    public function emettre(Request $request): JsonResponse
    {
        $appareil = $this->appareilConnecte($request);
        if ($appareil->mode !== Appareil::BORNE) {
            return response()->json(['message' => 'Seule une borne donne des tickets.'], 403);
        }

        $donnees = $request->validate([
            'service_uuid' => 'required|uuid',
            // L'urgence se déclare à l'accueil, pas à la borne.
            'priorite' => ['sometimes', Rule::in([Priorite::Prioritaire->value, Priorite::Ordinaire->value])],
            'telephone' => 'sometimes|nullable|string|max:20',
        ]);
        $service = $this->servicesDe($appareil)->where('uuid', $donnees['service_uuid'])->firstOrFail();

        $ticket = $this->file->emettre(
            $service,
            Priorite::from($donnees['priorite'] ?? Priorite::Ordinaire->value),
            'borne',
            ['telephone' => $donnees['telephone'] ?? null],
        );

        return response()->json(['ticket' => $this->file->pourPublic($ticket)], 201);
    }

    // ------------------------------------------------------------ interne

    /**
     * @return array{appareil: Appareil, code: string, qr: string, expire_at: string}
     */
    private function nouveauCode(Appareil $appareil): array
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Appareil::where('code_appairage', $code)->exists());

        $appareil->update([
            'code_appairage' => $code,
            'code_expire_at' => now()->addMinutes(self::VALIDITE_MIN),
        ]);

        return [
            'appareil' => $appareil->fresh() ?? $appareil,
            'code' => $code,
            'qr' => 'ngoni-sante:appairer:'.$code,
            'expire_at' => now()->addMinutes(self::VALIDITE_MIN)->toIso8601String(),
        ];
    }

    private function appareil(string $uuid): Appareil
    {
        return Appareil::where('etablissement_id', $this->currentUser()->etablissement_id)
            ->where('uuid', $uuid)
            ->whereNull('revoque_at')
            ->firstOrFail();
    }

    private function appareilConnecte(Request $request): Appareil
    {
        $appareil = $request->attributes->get('appareil');
        if (! $appareil instanceof Appareil) {
            abort(401);
        }

        return $appareil;
    }

    /**
     * @return Builder<Service>
     */
    private function servicesDe(Appareil $appareil): Builder
    {
        return Service::where('etablissement_id', $appareil->etablissement_id)
            ->where('actif', true)
            ->when($appareil->services !== null, fn ($q) => $q->whereIn('uuid', $appareil->services ?? []))
            ->orderBy('ordre')
            ->orderBy('nom');
    }
}
