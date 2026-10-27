<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RendezVous\PublicStoreRequest;
use App\Models\Etablissement;
use App\Models\RendezVous;
use App\Models\User;
use App\Sante\Profil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints publics (sans authentification) destinés au grand public / patients :
 * recherche d'établissements de santé et prise de rendez-vous.
 *
 * @tag Public
 */
class PublicController extends Controller
{
    /**
     * Recherche d'établissements de santé, triés par proximité si lat/lng fournis.
     */
    public function searchEtablissements(Request $request): JsonResponse
    {
        $lat = $this->num($request->query('lat'));
        $lng = $this->num($request->query('lng'));
        $radius = $this->num($request->query('radius_km'));
        $type = $request->query('type');
        $profil = $request->query('profil');
        $q = $request->query('q');

        $limit = $request->integer('limit', 50);
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $query = Etablissement::where('actif', true);
        if (is_string($type) && in_array($type, ['cabinet', 'pharmacie', 'cabinet_pharmacie'], true)) {
            $query->where('type', $type);
        }
        if (is_string($profil) && Profil::tryFrom($profil) !== null) {
            $query->where('profil', $profil);
        }
        if (is_string($q) && $q !== '') {
            $query->where('nom', 'like', '%'.$q.'%');
        }

        /** @var list<array{etab: Etablissement, distance: float|null}> $rows */
        $rows = [];
        foreach ($query->get() as $etablissement) {
            $etabLat = $this->num($etablissement->latitude);
            $etabLng = $this->num($etablissement->longitude);
            $distance = null;

            if ($lat !== null && $lng !== null) {
                if ($etabLat === null || $etabLng === null) {
                    continue; // exclu de la recherche géo s'il n'a pas de coordonnées
                }
                $distance = $this->distanceKm($lat, $lng, $etabLat, $etabLng);
                if ($radius !== null && $distance > $radius) {
                    continue;
                }
            }

            $rows[] = ['etab' => $etablissement, 'distance' => $distance];
        }

        if ($lat !== null && $lng !== null) {
            usort($rows, fn (array $a, array $b): int => ($a['distance'] ?? 0.0) <=> ($b['distance'] ?? 0.0));
        }

        $rows = array_slice($rows, 0, $limit);
        $data = array_map(fn (array $r): array => $this->serializeEtablissement($r['etab'], $r['distance']), $rows);

        return response()->json(['data' => $data]);
    }

    /**
     * Prise de rendez-vous par un patient (statut initial : "demande").
     */
    public function storeRendezVous(PublicStoreRequest $request): JsonResponse
    {
        $etablissement = Etablissement::where('uuid', $request->string('etablissement_uuid')->toString())
            ->where('actif', true)
            ->first();

        if ($etablissement === null) {
            return response()->json(['message' => 'Établissement introuvable ou inactif.'], 404);
        }

        $medecinId = null;
        $medecinUuid = $request->input('medecin_uuid');
        if (is_string($medecinUuid)) {
            $medecin = User::where('uuid', $medecinUuid)
                ->where('etablissement_id', $etablissement->id)
                ->first();
            if ($medecin === null) {
                return response()->json(['message' => 'Médecin introuvable dans cet établissement.'], 422);
            }
            $medecinId = $medecin->id;
        }

        /** @var array<string, mixed> $attrs */
        $attrs = [
            'etablissement_id' => $etablissement->id,
            'medecin_id' => $medecinId,
            'patient_nom' => $request->string('patient_nom')->toString(),
            'patient_telephone' => $request->string('patient_telephone')->toString(),
            'date_souhaitee' => $request->date('date_souhaitee'),
            'motif' => $request->input('motif'),
            'statut' => 'demande',
        ];

        $uuid = $request->input('uuid');
        $rendezVous = is_string($uuid)
            ? RendezVous::firstOrCreate(['uuid' => $uuid], $attrs)
            : RendezVous::create($attrs);

        return response()->json([
            'message' => 'Demande de rendez-vous enregistrée.',
            'rendez_vous' => [
                'uuid' => $rendezVous->uuid,
                'statut' => $rendezVous->statut,
                'date_souhaitee' => $rendezVous->date_souhaitee->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEtablissement(Etablissement $e, ?float $distanceKm): array
    {
        return [
            'uuid' => $e->uuid,
            'nom' => $e->nom,
            'type' => $e->type,
            'profil' => $e->profil,
            'profil_label' => Profil::tryFrom($e->profil)?->label(),
            'modules' => $e->modules,
            'pays_code' => $e->pays_code,
            'devise' => $e->devise,
            'telephone' => $e->telephone,
            'adresse' => $e->adresse,
            'latitude' => $e->latitude,
            'longitude' => $e->longitude,
            'itineraire_url' => $e->itineraire_url,
            'distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
        ];
    }

    /**
     * Distance Haversine en km (calcul en PHP → compatible SQLite en tests).
     */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
