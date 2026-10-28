<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Pays;
use App\Models\Personne;
use App\Sante\Telephone;
use Illuminate\Console\Command;

/**
 * Donne (ou retire) l'accès à la console Ngoni Santé à une personne, par son
 * numéro de téléphone.
 *
 *     php artisan sante:admin-plateforme 73136789
 *     php artisan sante:admin-plateforme 73136789 --retirer
 */
class AdminPlateforme extends Command
{
    protected $signature = 'sante:admin-plateforme {telephone} {--pays=ML} {--retirer}';

    protected $description = 'Donne ou retire l’accès à la console Ngoni Santé';

    public function handle(): int
    {
        $pays = Pays::findOrFail((string) $this->option('pays'));
        $telephone = Telephone::normaliser((string) $this->argument('telephone'), $pays);
        $personne = Personne::where('telephone', $telephone)->first();
        if ($personne === null) {
            $this->error("Aucun compte Ngoni Santé avec le numéro {$telephone}.");

            return self::FAILURE;
        }

        $retirer = (bool) $this->option('retirer');
        $personne->update(['admin_plateforme' => ! $retirer]);
        $this->info($retirer
            ? "✓ {$personne->nom} n’a plus accès à la console."
            : "✓ {$personne->nom} a accès à la console Ngoni Santé.");

        return self::SUCCESS;
    }
}
