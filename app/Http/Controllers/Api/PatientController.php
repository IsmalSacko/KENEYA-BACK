<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(): JsonResponse
    {
        $patients = $this->scopedToEtablissement(Patient::query())
            ->latest()
            ->get();

        return response()->json($patients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'telephone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
        ]);

        $data['etablissement_id'] = $this->currentUser()->etablissement_id;
        $patient = Patient::create($data);

        return response()->json([
            'message' => 'Patient créé avec succès.',
            'patient' => $patient,
        ], 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        $this->authorizeSameEtablissement($patient);

        return response()->json($patient);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $this->authorizeSameEtablissement($patient);

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

    public function destroy(Patient $patient): JsonResponse
    {
        $this->authorizeSameEtablissement($patient);

        $patient->delete();

        return response()->json(['message' => 'Patient supprimé avec succès.']);
    }
}
