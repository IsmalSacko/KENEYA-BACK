<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\Request;

class ConsultationController extends Controller
{
    public function index()
    {
        $consultations = Consultation::with(['patient', 'medecin', 'paiement'])
            ->where('etablissement_id', auth()->user()->etablissement_id)
            ->latest()
            ->get();

        return response()->json($consultations);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'required|integer|exists:patients,id',
            'medecin_id' => 'required|integer|exists:users,id',
            'motif' => 'required|string|max:255',
            'montant' => 'required|numeric|min:0',
            'statut' => 'nullable|in:en_attente,payee',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        $patient = Patient::where('id', $data['patient_id'])->where('etablissement_id', $etablissementId)->first();
        $medecin = User::where('id', $data['medecin_id'])->where('etablissement_id', $etablissementId)->first();

        if (!$patient) {
            return response()->json(['message' => 'Patient introuvable dans votre établissement.'], 404);
        }
        if (!$medecin) {
            return response()->json(['message' => 'Médecin introuvable dans votre établissement.'], 404);
        }

        $consultation = Consultation::create([
            'etablissement_id' => $etablissementId,
            'patient_id' => $data['patient_id'],
            'medecin_id' => $data['medecin_id'],
            'motif' => $data['motif'],
            'montant' => $data['montant'],
            'statut' => $data['statut'] ?? 'en_attente',
        ]);

        return response()->json([
            'message' => 'Consultation créée avec succès.',
            'consultation' => $consultation->load(['patient', 'medecin']),
        ], 201);
    }

    public function show(Consultation $consultation)
    {
        if ($consultation->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($consultation->load(['patient', 'medecin', 'paiement']));
    }

    public function update(Request $request, Consultation $consultation)
    {
        if ($consultation->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'patient_id' => 'sometimes|integer|exists:patients,id',
            'medecin_id' => 'sometimes|integer|exists:users,id',
            'motif' => 'sometimes|string|max:255',
            'montant' => 'sometimes|numeric|min:0',
            'statut' => 'sometimes|in:en_attente,payee',
        ]);

        $etablissementId = $request->user()->etablissement_id;
        if (isset($data['patient_id'])) {
            $patientOk = Patient::where('id', $data['patient_id'])->where('etablissement_id', $etablissementId)->exists();
            if (!$patientOk) {
                return response()->json(['message' => 'Patient introuvable dans votre établissement.'], 404);
            }
        }
        if (isset($data['medecin_id'])) {
            $medecinOk = User::where('id', $data['medecin_id'])->where('etablissement_id', $etablissementId)->exists();
            if (!$medecinOk) {
                return response()->json(['message' => 'Médecin introuvable dans votre établissement.'], 404);
            }
        }

        $consultation->update($data);

        return response()->json([
            'message' => 'Consultation mise à jour avec succès.',
            'consultation' => $consultation->fresh()->load(['patient', 'medecin', 'paiement']),
        ]);
    }

    public function destroy(Consultation $consultation)
    {
        if ($consultation->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $consultation->delete();

        return response()->json(['message' => 'Consultation supprimée avec succès.']);
    }
}
