<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** @tag Statistiques */
class StatsController extends Controller
{
    /**
     * Statistiques utilisateurs de l'établissement de l'admin courant :
     * effectifs, actifs/inactifs, répartition par rôle, inscriptions sur les
     * 12 derniers mois et tendance (croissance / baisse).
     */
    public function users(): JsonResponse
    {
        $user = $this->currentUser();

        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Accès refusé',
                'reason' => 'Réservé aux administrateurs.',
            ], 403);
        }

        $scopeId = $user->etablissement_id;
        $users = User::where('etablissement_id', $scopeId)->get();

        $total = $users->count();
        $actifs = $users->where('actif', true)->count();

        $parRole = [];
        foreach (['admin', 'medecin', 'pharmacien', 'caissier'] as $role) {
            $parRole[$role] = $users->where('role', $role)->count();
        }

        // 12 derniers mois (buckets Y-m).
        $now = Carbon::now();
        $bucket = [];
        $inscriptionsParMois = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->startOfMonth()->subMonths($i);
            $key = $m->format('Y-m');
            $bucket[$key] = 0;
            $inscriptionsParMois[] = ['mois' => $key, 'label' => $m->format('m/Y'), 'total' => 0];
        }

        foreach ($users as $u) {
            $key = $u->created_at?->format('Y-m');
            if ($key !== null && array_key_exists($key, $bucket)) {
                $bucket[$key]++;
            }
        }

        foreach ($inscriptionsParMois as $idx => $entry) {
            $inscriptionsParMois[$idx]['total'] = $bucket[$entry['mois']];
        }

        $ceMois = $bucket[$now->format('Y-m')] ?? 0;
        $moisPrecedent = $bucket[$now->copy()->subMonth()->format('Y-m')] ?? 0;
        $variation = $moisPrecedent > 0
            ? (($ceMois - $moisPrecedent) / $moisPrecedent) * 100
            : ($ceMois > 0 ? 100.0 : 0.0);

        return response()->json([
            'total' => $total,
            'actifs' => $actifs,
            'inactifs' => $total - $actifs,
            'taux_activite' => $total > 0 ? round($actifs / $total * 100, 1) : 0.0,
            'par_role' => $parRole,
            'nouveaux_ce_mois' => $ceMois,
            'nouveaux_mois_precedent' => $moisPrecedent,
            'variation_pct' => round($variation, 1),
            'inscriptions_par_mois' => $inscriptionsParMois,
        ]);
    }
}
