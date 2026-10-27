<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cpn;
use App\Models\Dossier;
use App\Models\Personne;
use App\Sante\Maternite\Maternite;
use App\Sante\Sms\EnvoiSms;
use Illuminate\Console\Command;

/**
 * Rappels SMS de la maternité, chaque matin : la CPN de demain, les vaccins
 * prévus demain, et ceux qui ont une semaine de retard.
 */
class RappelsMaternite extends Command
{
    protected $signature = 'sante:rappels-maternite';

    protected $description = 'Envoie les SMS de rappel des CPN et des vaccins';

    public function handle(EnvoiSms $sms, Maternite $maternite): int
    {
        $demain = now()->addDay()->toDateString();
        $envoyes = 0;

        $cpns = Cpn::with('etablissement', 'grossesse.dossier')
            ->whereDate('prochain_rdv', $demain)
            ->whereNull('rappel_at')
            ->whereHas('grossesse', fn ($g) => $g->where('statut', 'en_cours'))
            ->get();
        foreach ($cpns as $cpn) {
            $tel = $this->telephone($cpn->grossesse->dossier);
            if ($tel !== null) {
                $sms->envoyer($tel, "Ngoni Santé : votre consultation prénatale est demain à {$cpn->etablissement->nom}. Apportez votre carnet.");
                $envoyes++;
            }
            $cpn->update(['rappel_at' => now()]);
        }

        $enRetard = now()->subDays(7)->toDateString();
        $enfants = Dossier::whereNotNull('date_naissance')
            ->where('date_naissance', '>=', now()->subYears(2)->toDateString())
            ->where(fn ($q) => $q->whereNull('rappel_vaccins_le')->orWhere('rappel_vaccins_le', '<', now()->toDateString()))
            ->get();
        foreach ($enfants as $enfant) {
            $aFaire = array_filter($maternite->carnet($enfant), fn (array $v): bool => $v['fait_le'] === null);
            $prevus = implode(', ', array_column(array_filter($aFaire, fn (array $v): bool => $v['prevu_le'] === $demain), 'nom'));
            $oublies = implode(', ', array_column(array_filter($aFaire, fn (array $v): bool => $v['prevu_le'] === $enRetard), 'nom'));
            $tel = $this->telephone($enfant);
            if ($tel === null || ($prevus === '' && $oublies === '')) {
                continue;
            }
            $sms->envoyer($tel, $prevus !== ''
                ? "Ngoni Santé : {$enfant->nom} doit être vacciné(e) demain ({$prevus}). Apportez son carnet."
                : "Ngoni Santé : un vaccin de {$enfant->nom} a une semaine de retard ({$oublies}). Passez au centre de santé.");
            $enfant->update(['rappel_vaccins_le' => now()->toDateString()]);
            $envoyes++;
        }

        $this->info("{$envoyes} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }

    /** Le téléphone de la personne, ou celui de sa mère pour un enfant. */
    private function telephone(Dossier $dossier): ?string
    {
        if ($dossier->personne_id !== null) {
            return Personne::find($dossier->personne_id)?->telephone;
        }
        if ($dossier->responsable_id !== null && ($mere = Dossier::find($dossier->responsable_id)) !== null) {
            return $this->telephone($mere);
        }

        return $dossier->telephone;
    }
}
