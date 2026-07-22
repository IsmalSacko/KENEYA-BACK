<?php

namespace App\Http\Controllers\Api;

use App\Models\Medicament;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class MedicamentController extends Controller
{
    public function index()
    {
        $query = Medicament::where('etablissement_id', auth()->user()->etablissement_id);

        if (request()->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'seuil_alerte');
        }

        $medicaments = $query->latest()->get();

        return response()->json($medicaments);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'stock' => 'nullable|integer|min:0',
            'prix_unitaire' => 'required|numeric|min:0',
            'seuil_alerte' => 'nullable|integer|min:0',
            'date_expiration' => 'nullable|date',
            'actif' => 'nullable|boolean',
        ]);

        $data['etablissement_id'] = $request->user()->etablissement_id;
        $data['stock'] = $data['stock'] ?? 0;
        $data['seuil_alerte'] = $data['seuil_alerte'] ?? 5;
        $data['actif'] = $data['actif'] ?? true;

        $medicament = Medicament::create($data);

        return response()->json([
            'message' => 'Médicament créé avec succès.',
            'medicament' => $medicament,
        ], 201);
    }

    public function show(Medicament $medicament)
    {
        if ($medicament->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($medicament);
    }

    public function update(Request $request, Medicament $medicament)
    {
        if ($medicament->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'stock' => 'sometimes|integer|min:0',
            'prix_unitaire' => 'sometimes|numeric|min:0',
            'seuil_alerte' => 'sometimes|integer|min:0',
            'date_expiration' => 'sometimes|nullable|date',
            'actif' => 'sometimes|boolean',
        ]);

        $medicament->update($data);

        return response()->json([
            'message' => 'Médicament mis à jour avec succès.',
            'medicament' => $medicament->fresh(),
        ]);
    }

    public function destroy(Medicament $medicament)
    {
        if ($medicament->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $medicament->delete();

        return response()->json(['message' => 'Médicament supprimé avec succès.']);
    }
}
