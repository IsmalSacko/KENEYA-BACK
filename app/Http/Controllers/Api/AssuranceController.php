<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assureur;
use App\Models\Etablissement;
use App\Models\Visite;
use App\Sante\Caisse\Caisse;
use App\Sante\Refus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les assurances : assureurs, couverture de la visite, bordereau du mois.
 *
 * @tag Assurances
 */
class AssuranceController extends Controller
{
    public function __construct(private readonly Caisse $caisse) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Assureur::where('etablissement_id', $this->etablissement()->id)->orderBy('nom')->get()
                ->map(fn (Assureur $a): array => $this->assureur($a)),
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:120',
            'taux' => 'required|integer|between:1,100',
            'plafond' => 'sometimes|nullable|integer|min:1',
        ]);
        $a = Assureur::create([...$donnees, 'etablissement_id' => $this->etablissement()->id]);

        return response()->json(['assureur' => $this->assureur($a)], 201);
    }

    public function modifier(Request $request, string $uuid): JsonResponse
    {
        $a = Assureur::where('etablissement_id', $this->etablissement()->id)->where('uuid', $uuid)->firstOrFail();
        $a->update($request->validate([
            'nom' => 'sometimes|string|max:120',
            'taux' => 'sometimes|integer|between:1,100',
            'plafond' => 'sometimes|nullable|integer|min:1',
            'actif' => 'sometimes|boolean',
        ]));

        return response()->json(['assureur' => $this->assureur($a)]);
    }

    /** Le patient montre sa carte d'assuré : la visite est couverte. */
    public function couvrir(Request $request, string $uuid): JsonResponse
    {
        $etab = $this->etablissement();
        $donnees = $request->validate([
            'assureur_uuid' => 'required|string',
            'numero' => 'required|string|max:40',
            'taux' => 'sometimes|nullable|integer|between:1,100',
        ]);
        $visite = Visite::where('etablissement_id', $etab->id)->where('uuid', $uuid)->firstOrFail();
        $assureur = Assureur::where('etablissement_id', $etab->id)->where('actif', true)->where('uuid', $donnees['assureur_uuid'])->firstOrFail();
        try {
            $visite = $this->caisse->couvrir($visite, $assureur, $donnees['numero'], $donnees['taux'] ?? null);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['visite' => $this->caisse->pourAffichage($visite)]);
    }

    public function retirer(string $uuid): JsonResponse
    {
        $visite = Visite::where('etablissement_id', $this->etablissement()->id)->where('uuid', $uuid)->firstOrFail();
        try {
            $visite = $this->caisse->couvrir($visite, null);
        } catch (Refus $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['visite' => $this->caisse->pourAffichage($visite)]);
    }

    /** Le bordereau du mois, à envoyer à l'assureur (JSON, ou CSV avec ?format=csv). */
    public function bordereau(Request $request): JsonResponse|StreamedResponse
    {
        $etab = $this->etablissement();
        $donnees = $request->validate(['assureur' => 'required|string', 'mois' => 'required|date_format:Y-m', 'format' => 'sometimes|in:json,csv']);
        $assureur = Assureur::withTrashed()->where('etablissement_id', $etab->id)->where('uuid', $donnees['assureur'])->firstOrFail();
        $b = $this->caisse->bordereau($etab, $assureur, $donnees['mois']);

        if (($donnees['format'] ?? 'json') !== 'csv') {
            return response()->json($b);
        }

        return response()->streamDownload(function () use ($b): void {
            $f = fopen('php://output', 'w');
            if ($f === false) {
                return;
            }
            fwrite($f, "\u{FEFF}"); // Excel lit alors les accents
            fputcsv($f, ['Date', 'Patient', 'N° assuré', 'Total', 'Taux %', "Part assureur ({$b['devise']})"], ';');
            foreach ($b['lignes'] as $l) {
                fputcsv($f, [$l['date'], $l['patient'], $l['numero_assure'], $l['total'], $l['taux'], $l['part_assureur']], ';');
            }
            fputcsv($f, ['', '', '', '', 'Total', $b['total']], ';');
            fclose($f);
        }, "bordereau-{$b['mois']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** L'établissement du membre, s'il a le module Assurances. */
    private function etablissement(): Etablissement
    {
        $etab = Etablissement::findOrFail($this->currentUser()->etablissement_id);
        abort_unless(in_array('assurances', $etab->modules, true), 403, 'Module Assurances non activé.');

        return $etab;
    }

    /**
     * @return array<string, mixed>
     */
    private function assureur(Assureur $a): array
    {
        return ['uuid' => $a->uuid, 'nom' => $a->nom, 'taux' => $a->taux, 'plafond' => $a->plafond, 'actif' => $a->actif];
    }
}
