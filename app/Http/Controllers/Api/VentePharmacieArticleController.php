<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\VentePharmacieArticle;
use App\Models\VentesPharmacie;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class VentePharmacieArticleController extends Controller
{
    public function index()
    {
        $articles = VentePharmacieArticle::with(['medicament', 'ventePharmacie'])
            ->whereHas('ventePharmacie', function ($query) {
                $query->where('etablissement_id', auth()->user()->etablissement_id);
            })
            ->latest()
            ->get();

        return response()->json($articles);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vente_pharmacie_id' => 'required|integer|exists:ventes_pharmacie,id',
            'medicament_id' => 'required|integer|exists:medicaments,id',
            'quantite' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($data) {
            $vente = VentesPharmacie::where('id', $data['vente_pharmacie_id'])
                ->where('etablissement_id', auth()->user()->etablissement_id)
                ->first();

            if (!$vente) {
                return response()->json(['message' => 'Vente introuvable dans votre établissement.'], 404);
            }

            $medicament = Medicament::where('id', $data['medicament_id'])
                ->where('etablissement_id', $vente->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicament) {
                return response()->json(['message' => 'Médicament introuvable dans votre établissement.'], 404);
            }

            if ($medicament->stock < $data['quantite']) {
                return response()->json(['message' => 'Stock insuffisant pour cet article.'], 422);
            }

            $prixUnitaire = $medicament->prix_unitaire;
            $prixTotal = $prixUnitaire * $data['quantite'];

            $article = VentePharmacieArticle::create([
                'vente_pharmacie_id' => $vente->id,
                'medicament_id' => $medicament->id,
                'quantite' => $data['quantite'],
                'prix_unitaire' => $prixUnitaire,
                'prix_total' => $prixTotal,
            ]);

            $medicament->decrement('stock', $data['quantite']);
            $vente->increment('montant_total', $prixTotal);

            return response()->json([
                'message' => 'Article ajouté à la vente avec succès.',
                'article' => $article->load(['medicament', 'ventePharmacie']),
            ], 201);
        });
    }

    public function show(VentePharmacieArticle $ventePharmacieArticle)
    {
        $vente = $ventePharmacieArticle->ventePharmacie;
        if (!$vente || $vente->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($ventePharmacieArticle->load(['medicament', 'ventePharmacie']));
    }

    public function update(Request $request, VentePharmacieArticle $ventePharmacieArticle)
    {
        $vente = $ventePharmacieArticle->ventePharmacie;
        if (!$vente || $vente->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'quantite' => 'sometimes|integer|min:1',
        ]);

        if (!array_key_exists('quantite', $data)) {
            return response()->json([
                'message' => 'Aucune modification envoyée.',
                'article' => $ventePharmacieArticle->load(['medicament', 'ventePharmacie']),
            ]);
        }

        return DB::transaction(function () use ($ventePharmacieArticle, $vente, $data) {
            $medicament = Medicament::where('id', $ventePharmacieArticle->medicament_id)
                ->where('etablissement_id', $vente->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicament) {
                return response()->json(['message' => 'Médicament introuvable.'], 404);
            }

            $oldQuantite = (int) $ventePharmacieArticle->quantite;
            $newQuantite = (int) $data['quantite'];
            $delta = $newQuantite - $oldQuantite;

            if ($delta > 0 && $medicament->stock < $delta) {
                return response()->json(['message' => 'Stock insuffisant pour augmenter la quantité.'], 422);
            }

            if ($delta > 0) {
                $medicament->decrement('stock', $delta);
            } elseif ($delta < 0) {
                $medicament->increment('stock', abs($delta));
            }

            $newPrixTotal = $ventePharmacieArticle->prix_unitaire * $newQuantite;
            $prixDelta = $newPrixTotal - $ventePharmacieArticle->prix_total;

            $ventePharmacieArticle->update([
                'quantite' => $newQuantite,
                'prix_total' => $newPrixTotal,
            ]);

            $vente->increment('montant_total', $prixDelta);

            return response()->json([
                'message' => 'Article de vente mis à jour avec succès.',
                'article' => $ventePharmacieArticle->fresh()->load(['medicament', 'ventePharmacie']),
            ]);
        });
    }

    public function destroy(VentePharmacieArticle $ventePharmacieArticle)
    {
        $vente = $ventePharmacieArticle->ventePharmacie;
        if (!$vente || $vente->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return DB::transaction(function () use ($ventePharmacieArticle, $vente) {
            $medicament = Medicament::where('id', $ventePharmacieArticle->medicament_id)
                ->where('etablissement_id', $vente->etablissement_id)
                ->lockForUpdate()
                ->first();

            if ($medicament) {
                $medicament->increment('stock', $ventePharmacieArticle->quantite);
            }

            $vente->decrement('montant_total', $ventePharmacieArticle->prix_total);
            $ventePharmacieArticle->delete();

            return response()->json(['message' => 'Article supprimé avec succès.']);
        });
    }
}
