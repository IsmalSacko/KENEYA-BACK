<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Etablissement\StoreRequest;
use App\Http\Requests\Etablissement\UpdateRequest;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** @tag Etablissements */
class EtablissementController extends Controller
{
    public function store(StoreRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        // Créer un établissement est une action d'INSTANCE (plateforme) :
        // réservée à l'admin d'instance. Un admin d'établissement ne gère que le sien.
        if (! $user->isInstanceAdmin()) {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => "Seul l'administrateur de l'instance peut créer un établissement.",
            ], 403);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // uuid client -> création idempotente (offline rejoué).
        $uuid = $request->input('uuid');
        if (is_string($uuid)) {
            unset($data['uuid']);
            $etablissement = Etablissement::firstOrCreate(['uuid' => $uuid], $data);
        } else {
            $etablissement = Etablissement::create($data);
        }

        // Rattache l'établissement à l'administrateur (idempotent).
        $user->etablissements()->syncWithoutDetaching([$etablissement->id]);

        return response()->json([
            'message' => 'Établissement créé avec succès.',
            'etablissement' => $etablissement->fresh(),
        ], 201);
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $user->load('etablissements');

        return response()->json($user);
    }

    public function usersDeMesEtablissements(): JsonResponse
    {
        // Lecture seule : tout le personnel peut voir les collègues de son
        // établissement. La création/modification/suppression reste réservée
        // aux administrateurs (voir AuthController).
        $etablissementIds = $this->currentUser()->etablissements()->pluck('etablissements.id');

        $users = User::whereHas('etablissements', function ($query) use ($etablissementIds) {
            $query->whereIn('etablissements.id', $etablissementIds);
        })->distinct()->get();

        return response()->json($users);
    }

    public function show(Etablissement $etablissement): JsonResponse
    {
        $user = $this->currentUser();

        if (! $this->estSonEtablissement($etablissement)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($etablissement);
    }

    public function update(UpdateRequest $request, Etablissement $etablissement): JsonResponse
    {
        $user = $this->currentUser();

        // Modifier un établissement = pouvoir d'administration. L'admin d'instance
        // peut modifier n'importe lequel ; l'admin d'établissement, seulement le sien.
        $gereCetEtablissement = $user->isInstanceAdmin()
            || ($user->canAdministerEtablissement() && $this->estSonEtablissement($etablissement));

        if (! $gereCetEtablissement) {
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

    /**
     * L'établissement du membre connecté (ou un de ceux auxquels l'ancienne
     * table de liaison le rattache).
     */
    private function estSonEtablissement(Etablissement $etablissement): bool
    {
        $user = $this->currentUser();

        return $user->etablissement_id === $etablissement->id
            || $user->etablissements()->where('etablissements.id', $etablissement->id)->exists();
    }

    public function destroy(Etablissement $etablissement): JsonResponse
    {
        // Supprimer un établissement = action d'instance.
        if ($this->currentUser()->isInstanceAdmin()) {
            $etablissement->delete();

            return response()->json(['message' => 'Etablissement supprimé avec succès.']);
        }

        return response()->json(['message' => 'Accès refusé'], 403);
    }
}
