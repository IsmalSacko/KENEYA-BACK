<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use Illuminate\Http\Request;

class JournalAuditController extends Controller
{
    public function index()
    {
        $query = JournalAudit::with('utilisateur')
            ->where('etablissement_id', auth()->user()->etablissement_id)
            ->latest();

        if (request()->filled('action')) {
            $query->where('action', 'like', '%' . request('action') . '%');
        }

        $logs = $query->get();

        return response()->json($logs);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|string|max:255',
            'type_cible' => 'required|string|max:255',
            'id_cible' => 'nullable|integer|min:1',
        ]);

        $log = JournalAudit::create([
            'etablissement_id' => $request->user()->etablissement_id,
            'user_id' => $request->user()->id,
            'action' => $data['action'],
            'type_cible' => $data['type_cible'],
            'id_cible' => $data['id_cible'] ?? null,
        ]);

        return response()->json([
            'message' => 'Journal ajouté avec succès.',
            'journal' => $log->load('utilisateur'),
        ], 201);
    }

    public function show(JournalAudit $journalAudit)
    {
        if ($journalAudit->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($journalAudit->load('utilisateur'));
    }

    public function update(Request $request, JournalAudit $journalAudit)
    {
        if ($journalAudit->etablissement_id !== $request->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $data = $request->validate([
            'action' => 'sometimes|string|max:255',
            'type_cible' => 'sometimes|string|max:255',
            'id_cible' => 'sometimes|nullable|integer|min:1',
        ]);

        $journalAudit->update($data);

        return response()->json([
            'message' => 'Journal mis à jour avec succès.',
            'journal' => $journalAudit->fresh()->load('utilisateur'),
        ]);
    }

    public function destroy(JournalAudit $journalAudit)
    {
        if ($journalAudit->etablissement_id !== auth()->user()->etablissement_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Accès refusé. Action reservee a l\'admin.'], 403);
        }

        $journalAudit->delete();

        return response()->json(['message' => 'Journal supprimé avec succès.']);
    }
}
