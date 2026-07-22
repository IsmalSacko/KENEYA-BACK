<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Paiement;
use App\Models\VentesPharmacie;
use Illuminate\Http\Request;

class PaiementController extends Controller
{
    public function index()
    {
        $paiements = Paiement::with('source')
            ->where('etablissement_id', auth()->user()->etablissement_id)
            ->latest()
            ->get();

        return response()->json($paiements);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_type' => 'required|in:consultation,vente_pharmacie',
            'source_id' => 'required|integer|min:1',
            'montant' => 'required|numeric|min:0.01',
            'mode_paiement' => 'required|in:espece,orange,wave,moov',
            'reference_locale' => 'nullable|uuid',
        ]);

        [$modelClass, $source] = $this->resolveSource($data['source_type'], (int) $data['source_id'], $request->user()->etablissement_id);
        if (!$source) {
            return response()->json(['message' => 'Source introuvable dans votre établissement.'], 404);
        }

        $paiement = Paiement::create([
            'etablissement_id' => $request->user()->etablissement_id,
            'source_type' => $modelClass,
            'source_id' => $source->id,
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'reference_locale' => $data['reference_locale'] ?? null,
        ]);

        if ($source instanceof Consultation) {
            $source->update(['statut' => 'payee']);
        }

        return response()->json([
            'message' => 'Paiement créé avec succès.',
            'paiement' => $paiement->load('source'),
        ], 201);
    }

    public function show(Paiement $paiement)
    {
        if ($paiement->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($paiement->load('source'));
    }

    public function update(Request $request, Paiement $paiement)
    {
        if ($paiement->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'montant' => 'sometimes|numeric|min:0.01',
            'mode_paiement' => 'sometimes|in:espece,orange,wave,moov',
            'reference_locale' => 'sometimes|nullable|uuid',
        ]);

        $paiement->update($data);

        return response()->json([
            'message' => 'Paiement mis à jour avec succès.',
            'paiement' => $paiement->fresh()->load('source'),
        ]);
    }

    public function destroy(Paiement $paiement)
    {
        if ($paiement->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $paiement->delete();

        return response()->json(['message' => 'Paiement supprimé avec succès.']);
    }

    private function resolveSource(string $sourceType, int $sourceId, int $etablissementId): array
    {
        if ($sourceType === 'consultation') {
            $source = Consultation::where('id', $sourceId)
                ->where('etablissement_id', $etablissementId)
                ->first();

            return [Consultation::class, $source];
        }

        $source = VentesPharmacie::where('id', $sourceId)
            ->where('etablissement_id', $etablissementId)
            ->first();

        return [VentesPharmacie::class, $source];
    }
}
