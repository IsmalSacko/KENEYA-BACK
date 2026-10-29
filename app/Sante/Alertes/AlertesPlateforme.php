<?php

declare(strict_types=1);

namespace App\Sante\Alertes;

use App\Models\Etablissement;
use App\Models\Personne;
use App\Sante\Notifications\PushFirebase;
use App\Sante\Profil;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ce que l'équipe Ngoni Santé doit savoir tout de suite : un établissement
 * créé attend d'être vérifié avant d'être visible des patients.
 */
final class AlertesPlateforme
{
    public function etablissementAValider(Etablissement $etablissement, Personne $par): void
    {
        if ($etablissement->interne) {
            return;
        }
        $destinataires = array_values(array_filter(array_map('trim', explode(',', config()->string('sante.alertes.emails', '')))));
        $lien = rtrim(config()->string('sante.console.url'), '/').'/comptes/ngoni-sante/'.$etablissement->uuid;
        $corps = "Un nouvel établissement attend votre validation.\n\n"
            ."Nom : {$etablissement->nom}\n"
            .'Type : '.(Profil::tryFrom($etablissement->profil)?->label() ?? $etablissement->profil)."\n"
            .'Responsable : '.($etablissement->responsable ?? '—')."\n"
            .'N° d’autorisation : '.($etablissement->autorisation ?? 'pas encore donné')."\n"
            ."Créé par : {$par->nom} ({$par->telephone})\n\n"
            ."Valider dans la Console : {$lien}\n";

        // Après la réponse : l'app n'attend ni le serveur de mails ni Firebase.
        dispatch(function () use ($destinataires, $corps, $etablissement, $lien): void {
            if ($destinataires !== []) {
                try {
                    Mail::raw($corps, fn ($m) => $m->to($destinataires)->subject("Ngoni Santé — à valider : {$etablissement->nom}"));
                } catch (\Throwable $e) {
                    Log::warning('Alerte « établissement à valider » non envoyée : '.$e->getMessage());
                }
            }
            foreach ($this->equipe() as $membre) {
                app(PushFirebase::class)->aLaPersonne($membre, 'Établissement à valider', "{$etablissement->nom} attend votre validation.", ['lien' => $lien]);
            }
        })->afterResponse();
    }

    /** Validé : son créateur le sait tout de suite. */
    public function etablissementValide(Etablissement $etablissement): void
    {
        $proprietaire = $etablissement->proprietaire_id !== null ? Personne::find($etablissement->proprietaire_id) : null;
        if ($proprietaire === null) {
            return;
        }
        dispatch(fn () => app(PushFirebase::class)->aLaPersonne(
            $proprietaire,
            'Établissement validé',
            "{$etablissement->nom} est validé : les patients peuvent maintenant le trouver, prendre un ticket et un rendez-vous.",
        ))->afterResponse();
    }

    /**
     * L'équipe Ngoni Santé : les comptes dont le numéro est dans
     * SANTE_ALERTES_TELEPHONES.
     *
     * @return list<Personne>
     */
    private function equipe(): array
    {
        $numeros = array_values(array_filter(array_map('trim', explode(',', config()->string('sante.alertes.telephones', '')))));

        return $numeros === [] ? [] : array_values(Personne::whereIn('telephone', $numeros)->get()->all());
    }
}
