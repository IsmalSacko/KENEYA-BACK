<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RendezVous\UpdateRequest;
use App\Models\RendezVous;
use Illuminate\Http\JsonResponse;

/** @tag Rendez-vous */
class RendezVousController extends Controller
{
    /**
     * Demandes de rendez-vous de l'établissement courant.
     */
    public function index(): JsonResponse
    {
        $rendezVous = $this->scopedToEtablissement(RendezVous::query())
            ->latest()
            ->get();

        return response()->json($rendezVous);
    }

    /**
     * Traitement d'une demande (confirmation, refus, annulation…).
     */
    public function update(UpdateRequest $request, RendezVous $rendezVous): JsonResponse
    {
        $this->authorizeSameEtablissement($rendezVous);

        $rendezVous->update($request->validated());

        return response()->json([
            'message' => 'Rendez-vous mis à jour.',
            'rendez_vous' => $rendezVous->fresh(),
        ]);
    }
}
