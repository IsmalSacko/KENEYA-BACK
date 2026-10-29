<?php

declare(strict_types=1);

namespace App\Sante\Alertes;

use App\Models\Etablissement;
use App\Models\Personne;
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
        $destinataires = array_values(array_filter(array_map('trim', explode(',', config()->string('sante.alertes.emails', '')))));
        if ($destinataires === [] || $etablissement->interne) {
            return;
        }
        $lien = rtrim(config()->string('sante.console.url'), '/').'/comptes/ngoni-sante/'.$etablissement->uuid;
        $corps = "Un nouvel établissement attend votre validation.\n\n"
            ."Nom : {$etablissement->nom}\n"
            .'Type : '.(Profil::tryFrom($etablissement->profil)?->label() ?? $etablissement->profil)."\n"
            .'Responsable : '.($etablissement->responsable ?? '—')."\n"
            .'N° d’autorisation : '.($etablissement->autorisation ?? 'pas encore donné')."\n"
            ."Créé par : {$par->nom} ({$par->telephone})\n\n"
            ."Valider dans la Console : {$lien}\n";

        // Après la réponse : l'app n'attend pas le serveur de mails.
        dispatch(function () use ($destinataires, $corps, $etablissement): void {
            try {
                Mail::raw($corps, fn ($m) => $m->to($destinataires)->subject("Ngoni Santé — à valider : {$etablissement->nom}"));
            } catch (\Throwable $e) {
                Log::warning('Alerte « établissement à valider » non envoyée : '.$e->getMessage());
            }
        })->afterResponse();
    }
}
