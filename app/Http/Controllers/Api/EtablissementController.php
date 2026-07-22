<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Etablissement\UpdateRequest;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** @tag Etablissements */
class EtablissementController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $user->load('etablissements');

        return response()->json($user);
    }

    public function usersDeMesEtablissements(): JsonResponse
    {
        $etablissementIds = $this->currentUser()->etablissements()->pluck('etablissements.id');

        $users = User::whereHas('etablissements', function ($query) use ($etablissementIds) {
            $query->whereIn('etablissements.id', $etablissementIds);
        })->distinct()->get();

        return response()->json($users);
    }

    public function show(Etablissement $etablissement): JsonResponse
    {
        $user = $this->currentUser();

        if (! $user->etablissements()->where('etablissements.id', $etablissement->id)->exists()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($etablissement);
    }

    public function update(UpdateRequest $request, Etablissement $etablissement): JsonResponse
    {
        $user = $this->currentUser();

        if (
            ! $user->etablissements()
                ->where('etablissements.id', $etablissement->id)->exists() &&
            $user->role != 'admin'
        ) {
            return response()->json([
                'message' => 'Accès refusé',
            ], 403);
        }
        if ($request->filled('actif')) {
            $etablissement->actif = $request->boolean('actif');
        }
        $etablissement->update($request->validated());

        return response()->json([
            'message' => 'Etablissement mis à jour avec succès.',
            'etablissement' => $etablissement->fresh(),
        ]);
    }

    public function destroy(Etablissement $etablissement): JsonResponse
    {
        if ($this->currentUser()->role == 'admin') {
            $etablissement->delete();

            return response()->json(['message' => 'Etablissement supprimé avec succès.']);
        }

        return response()->json(['message' => 'Accès refusé'], 403);
    }
}
