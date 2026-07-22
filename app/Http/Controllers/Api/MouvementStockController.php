<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicament;
use App\Models\MouvementStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class MouvementStockController extends Controller
{
    public function index()
    {
        $mouvements = MouvementStock::with('medicament')
            ->where('etablissement_id', auth()->user()->etablissement_id)
            ->latest()
            ->get();

        return response()->json($mouvements);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'medicament_id' => 'required|exists:medicaments,id',
            'type' => 'required|in:entree,sortie,ajustement',
            'quantite' => 'required|integer|not_in:0',
            'commentaire' => 'nullable|string',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($data, $user) {
            $medicament = Medicament::where('id', $data['medicament_id'])
                ->where('etablissement_id', $user->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicament) {
                return response()->json(['message' => 'Médicament non trouvé dans votre établissement.'], 404);
            }

            $stockApres = $this->computeNextStock($medicament->stock, $data['type'], (int) $data['quantite']);
            if ($stockApres < 0) {
                return response()->json(['message' => 'Stock insuffisant pour cette opération.'], 422);
            }

            $mouvement = MouvementStock::create([
                'etablissement_id' => $user->etablissement_id,
                'medicament_id' => $medicament->id,
                'type' => $data['type'],
                'quantite' => $data['quantite'],
                'commentaire' => $data['commentaire'] ?? null,
            ]);

            $medicament->update(['stock' => $stockApres]);

            return response()->json([
                'message' => 'Mouvement de stock créé avec succès.',
                'mouvement' => $mouvement->load('medicament'),
                'stock_actuel' => $stockApres,
            ], 201);
        });
    }

    public function show(MouvementStock $mouvementStock)
    {
        if ($mouvementStock->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($mouvementStock->load('medicament'));
    }

    public function update(Request $request, MouvementStock $mouvementStock)
    {
        if ($mouvementStock->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'medicament_id' => 'sometimes|exists:medicaments,id',
            'type' => 'sometimes|in:entree,sortie,ajustement',
            'quantite' => 'sometimes|integer|not_in:0',
            'commentaire' => 'sometimes|nullable|string',
        ]);

        return DB::transaction(function () use ($request, $mouvementStock, $data) {
            $medicamentInitial = Medicament::where('id', $mouvementStock->medicament_id)
                ->where('etablissement_id', $request->user()->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicamentInitial) {
                return response()->json(['message' => 'Médicament initial introuvable.'], 404);
            }

            $medicamentNouveauId = $data['medicament_id'] ?? $mouvementStock->medicament_id;
            $medicamentNouveau = Medicament::where('id', $medicamentNouveauId)
                ->where('etablissement_id', $request->user()->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicamentNouveau) {
                return response()->json(['message' => 'Nouveau médicament introuvable.'], 404);
            }

            $typeNouveau = $data['type'] ?? $mouvementStock->type;
            $quantiteNouveau = (int) ($data['quantite'] ?? $mouvementStock->quantite);

            $stockRevert = $this->revertStock($medicamentInitial->stock, $mouvementStock->type, (int) $mouvementStock->quantite);
            if ($stockRevert < 0) {
                return response()->json(['message' => 'Stock invalide après annulation du mouvement existant.'], 422);
            }

            $medicamentInitial->update(['stock' => $stockRevert]);

            $baseStock = $medicamentNouveau->id === $medicamentInitial->id ? $stockRevert : $medicamentNouveau->stock;
            $stockApres = $this->computeNextStock($baseStock, $typeNouveau, $quantiteNouveau);
            if ($stockApres < 0) {
                return response()->json(['message' => 'Stock insuffisant pour appliquer la mise à jour.'], 422);
            }

            $medicamentNouveau->update(['stock' => $stockApres]);

            $mouvementStock->update([
                'medicament_id' => $medicamentNouveau->id,
                'type' => $typeNouveau,
                'quantite' => $quantiteNouveau,
                'commentaire' => array_key_exists('commentaire', $data) ? $data['commentaire'] : $mouvementStock->commentaire,
            ]);

            return response()->json([
                'message' => 'Mouvement de stock mis à jour avec succès.',
                'mouvement' => $mouvementStock->fresh()->load('medicament'),
            ]);
        });
    }

    public function destroy(MouvementStock $mouvementStock)
    {
        if ($mouvementStock->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return DB::transaction(function () use ($mouvementStock) {
            $medicament = Medicament::where('id', $mouvementStock->medicament_id)
                ->where('etablissement_id', auth()->user()->etablissement_id)
                ->lockForUpdate()
                ->first();

            if (!$medicament) {
                return response()->json(['message' => 'Médicament introuvable.'], 404);
            }

            $stockApres = $this->revertStock($medicament->stock, $mouvementStock->type, (int) $mouvementStock->quantite);
            if ($stockApres < 0) {
                return response()->json(['message' => 'Suppression impossible: stock incohérent.'], 422);
            }

            $medicament->update(['stock' => $stockApres]);
            $mouvementStock->delete();

            return response()->json(['message' => 'Mouvement de stock supprimé avec succès.']);
        });
    }

    private function computeNextStock(int $stockActuel, string $type, int $quantite): int
    {
        if ($type === 'entree') {
            return $stockActuel + abs($quantite);
        }
        if ($type === 'sortie') {
            return $stockActuel - abs($quantite);
        }

        return $stockActuel + $quantite;
    }

    private function revertStock(int $stockActuel, string $type, int $quantite): int
    {
        if ($type === 'entree') {
            return $stockActuel - abs($quantite);
        }
        if ($type === 'sortie') {
            return $stockActuel + abs($quantite);
        }

        return $stockActuel - $quantite;
    }
}
