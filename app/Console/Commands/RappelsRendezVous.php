<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RendezVous;
use App\Sante\Rdv\Agendas;
use App\Sante\Sms\EnvoiSms;
use Illuminate\Console\Command;

/**
 * Rappels par SMS : la veille (entre 24 h et 3 h avant) et peu avant
 * (moins de 2 h). Lancée toutes les 15 minutes par le planificateur.
 */
class RappelsRendezVous extends Command
{
    protected $signature = 'sante:rappels-rdv';

    protected $description = 'Envoie les SMS de rappel des rendez-vous';

    public function handle(EnvoiSms $sms, Agendas $agendas): int
    {
        $envoyes = 0;
        $aVenir = RendezVous::with('etablissement')
            ->whereIn('statut', ['demande', 'confirme'])
            ->whereNotNull('debut')
            ->whereBetween('debut', [now(), now()->addDay()])
            ->get();

        foreach ($aVenir as $rdv) {
            $debut = $rdv->debut;
            if ($debut === null || $rdv->patient_telephone === '') {
                continue;
            }
            $local = $debut->copy()->setTimezone($agendas->fuseau($rdv->etablissement));
            $heures = now()->diffInMinutes($debut, true) / 60;

            if ($heures <= 2 && $rdv->rappel_proche_at === null) {
                $sms->envoyer($rdv->patient_telephone, "Ngoni Santé : votre rendez-vous est à {$local->format('H:i')} à {$rdv->etablissement->nom}. À l'arrivée, scannez l'affiche de l'accueil ou montrez votre QR.");
                $rdv->update(['rappel_proche_at' => now(), 'rappel_veille_at' => $rdv->rappel_veille_at ?? now()]);
                $envoyes++;
            } elseif ($heures > 3 && $rdv->rappel_veille_at === null) {
                $sms->envoyer($rdv->patient_telephone, "Ngoni Santé : rappel, rendez-vous le {$local->format('d/m')} à {$local->format('H:i')} à {$rdv->etablissement->nom}. Empêché ? Annulez dans l'app pour libérer la place.");
                $rdv->update(['rappel_veille_at' => now()]);
                $envoyes++;
            }
        }

        $this->info("{$envoyes} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
