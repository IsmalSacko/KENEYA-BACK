<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\Patient;
use App\Models\VentePharmacieArticle;
use App\Models\VentesPharmacie;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class VentesPharmacieController extends Controller
{
    public function index()
    {
        $ventes = VentesPharmacie::with(['patient', 'articles.medicament', 'paiement'])
            ->where('etablissement_id', auth()->user()->etablissement_id)
            ->latest()
            ->get();

        return response()->json($ventes);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'nullable|integer|exists:patients,id',
            'mode_paiement' => 'required|in:espece,orange,wave,moov',
            'statut_sync' => 'nullable|in:en_attente,synchronise',
            'reference_locale' => 'nullable|uuid',
            'articles' => 'required|array|min:1',
            'articles.*.medicament_id' => 'required|integer|exists:medicaments,id',
            'articles.*.quantite' => 'required|integer|min:1',
        ]);

        $etablissementId = $request->user()->etablissement_id;

        if (!empty($data['patient_id'])) {
            $patientOk = Patient::where('id', $data['patient_id'])
                ->where('etablissement_id', $etablissementId)
                ->exists();
            if (!$patientOk) {
                return response()->json(['message' => 'Patient introuvable dans votre établissement.'], 404);
            }
        }

        return DB::transaction(function () use ($data, $etablissementId) {
            $montantTotal = 0;
            $saleLines = [];

            foreach ($data['articles'] as $line) {
                $medicament = Medicament::where('id', $line['medicament_id'])
                    ->where('etablissement_id', $etablissementId)
                    ->lockForUpdate()
                    ->first();

                if (!$medicament) {
                    return response()->json(['message' => 'Un médicament est introuvable dans votre établissement.'], 404);
                }

                if ($medicament->stock < $line['quantite']) {
                    return response()->json(['message' => "Stock insuffisant pour {$medicament->nom}."], 422);
                }

                $prixTotal = $medicament->prix_unitaire * $line['quantite'];
                $montantTotal += $prixTotal;
                $saleLines[] = [
                    'medicament' => $medicament,
                    'quantite' => $line['quantite'],
                    'prix_unitaire' => $medicament->prix_unitaire,
                    'prix_total' => $prixTotal,
                ];
            }

            $vente = VentesPharmacie::create([
                'etablissement_id' => $etablissementId,
                'patient_id' => $data['patient_id'] ?? null,
                'montant_total' => $montantTotal,
                'mode_paiement' => $data['mode_paiement'],
                'statut_sync' => $data['statut_sync'] ?? 'synchronise',
                'reference_locale' => $data['reference_locale'] ?? null,
            ]);

            foreach ($saleLines as $line) {
                VentePharmacieArticle::create([
                    'vente_pharmacie_id' => $vente->id,
                    'medicament_id' => $line['medicament']->id,
                    'quantite' => $line['quantite'],
                    'prix_unitaire' => $line['prix_unitaire'],
                    'prix_total' => $line['prix_total'],
                ]);

                $line['medicament']->decrement('stock', $line['quantite']);
            }

            return response()->json([
                'message' => 'Vente pharmacie créée avec succès.',
                'vente' => $vente->load(['patient', 'articles.medicament']),
            ], 201);
        });
    }

    public function show(VentesPharmacie $ventesPharmacie)
    {
        if ($ventesPharmacie->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($ventesPharmacie->load(['patient', 'articles.medicament', 'paiement']));
    }

    public function update(Request $request, VentesPharmacie $ventesPharmacie)
    {
        if ($ventesPharmacie->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'patient_id' => 'sometimes|nullable|integer|exists:patients,id',
            'mode_paiement' => 'sometimes|in:espece,orange,wave,moov',
            'statut_sync' => 'sometimes|in:en_attente,synchronise',
            'reference_locale' => 'sometimes|nullable|uuid',
        ]);

        if (array_key_exists('patient_id', $data) && !empty($data['patient_id'])) {
            $patientOk = Patient::where('id', $data['patient_id'])
                ->where('etablissement_id', $request->user()->etablissement_id)
                ->exists();
            if (!$patientOk) {
                return response()->json(['message' => 'Patient introuvable dans votre établissement.'], 404);
            }
        }

        $ventesPharmacie->update($data);

        return response()->json([
            'message' => 'Vente pharmacie mise à jour avec succès.',
            'vente' => $ventesPharmacie->fresh()->load(['patient', 'articles.medicament', 'paiement']),
        ]);
    }

    public function destroy(VentesPharmacie $ventesPharmacie)
    {
        if ($ventesPharmacie->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return DB::transaction(function () use ($ventesPharmacie) {
            $articles = $ventesPharmacie->articles()->get();
            foreach ($articles as $article) {
                $medicament = Medicament::where('id', $article->medicament_id)
                    ->where('etablissement_id', $ventesPharmacie->etablissement_id)
                    ->lockForUpdate()
                    ->first();

                if ($medicament) {
                    $medicament->increment('stock', $article->quantite);
                }
            }

            $ventesPharmacie->delete();

            return response()->json(['message' => 'Vente pharmacie supprimée avec succès.']);
        });
    }
}
