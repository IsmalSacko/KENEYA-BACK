<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DemandeSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Page publique de demande de suppression de compte / de données (RGPD,
 * exigence Google Play). Accessible sans authentification.
 */
class SuppressionCompteController extends Controller
{
    public function show(): View
    {
        return view('suppression_compte');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            [
                'nom' => 'nullable|string|max:255',
                'telephone' => 'required|string|max:20',
                'type_compte' => 'required|in:pro,patient',
                'motif' => 'nullable|string|max:1000',
                'confirmation' => 'accepted',
            ],
            [
                'telephone.required' => 'Le numéro de téléphone est obligatoire.',
                'type_compte.required' => 'Veuillez préciser le type de compte.',
                'confirmation.accepted' => 'Vous devez confirmer votre demande.',
            ],
        );

        $demande = DemandeSuppression::create([
            'nom' => $data['nom'] ?? null,
            'telephone' => $data['telephone'],
            'type_compte' => $data['type_compte'],
            'motif' => $data['motif'] ?? null,
            'statut' => 'en_attente',
        ]);

        $this->notifier($demande);

        return redirect()
            ->route('suppression-compte.show')
            ->with('success', true);
    }

    /**
     * Notifie l'administrateur (best-effort : n'interrompt jamais la demande).
     */
    private function notifier(DemandeSuppression $demande): void
    {
        $destinataire = config('mail.from.address');
        if (! is_string($destinataire) || $destinataire === '') {
            return;
        }

        try {
            $corps = "Nouvelle demande de suppression\n"
                ."Type : {$demande->type_compte}\n"
                ."Nom : {$demande->nom}\n"
                ."Téléphone : {$demande->telephone}\n"
                ."Motif : {$demande->motif}";

            Mail::raw($corps, function ($message) use ($destinataire): void {
                $message->to($destinataire)->subject('KENEYA — Demande de suppression');
            });
        } catch (\Throwable $e) {
            Log::warning('Notification de suppression non envoyée : '.$e->getMessage());
        }
    }
}
