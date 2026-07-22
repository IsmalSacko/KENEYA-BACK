<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index()
    {
        $patients = Patient::where('etablissement_id', auth()->user()->etablissement_id)
            ->latest()
            ->get();

        return response()->json($patients);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'telephone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
        ]);

        $data['etablissement_id'] = $request->user()->etablissement_id;
        $patient = Patient::create($data);

        return response()->json([
            'message' => 'Patient créé avec succès.',
            'patient' => $patient,
        ], 201);
    }

    public function show(Patient $patient)
    {
        if ($patient->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($patient);
    }

    public function update(Request $request, Patient $patient)
    {
        if ($patient->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'telephone' => 'sometimes|nullable|string|max:20',
            'adresse' => 'sometimes|nullable|string|max:255',
        ]);

        $patient->update($data);

        return response()->json([
            'message' => 'Patient mis à jour avec succès.',
            'patient' => $patient->fresh(),
        ]);
    }

    public function destroy(Patient $patient)
    {
        if ($patient->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $patient->delete();

        return response()->json(['message' => 'Patient supprimé avec succès.']);
    }
}
