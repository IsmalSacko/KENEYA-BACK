<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Etablissement\UpdateRequest;
use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class EtablissementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user()->load('etablissements');
        //
        return response()->json($user);

    }

    public function usersDeMesEtablissements()
    {
        $etablissementIds = auth()->user()->etablissements()->pluck('etablissements.id');

        $users = User::whereHas('etablissements', function ($query) use ($etablissementIds) {
            $query->whereIn('etablissements.id', $etablissementIds);
        })->distinct()->get();

        return response()->json($users);
    }



    public function show(Etablissement $etablissement)
    {
        $user = auth()->user()->load('etablissements');
        if (!$user->etablissements()->where('etablissements.id', $etablissement->id)->exists()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        return response()->json($etablissement);
    }






    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRequest $request, Etablissement $etablissement)
    {
        if (
            !$request->user()
                ->etablissements()
                ->where('etablissements.id', $etablissement->id)->exists() &&
            $request->user()->role != 'admin'
        ) {

            return response()->json([
                'message' => 'Accès refusé'
            ], 403);
        }
        if ($request->filled('actif')) {
            $etablissement->actif = $request->actif;
        }
        $etablissement->update($request->validated());

        return response()->json([
            'message' => 'Etablissement mis à jour avec succès.',
            'etablissement' => $etablissement->fresh()
        ]);
    }




    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Etablissement $etablissement)
    {
        if ($etablissement && auth()->user()->role == 'admin') {
            $etablissement->delete();
            return response()->json(['message' => 'Etablissement supprimé avec succès.']);
        }
        return response()->json(['message' => 'Accès refusé'], 403);
    }
}
