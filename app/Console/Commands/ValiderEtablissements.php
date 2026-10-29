<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Etablissement;
use App\Models\User;
use App\Sante\Alertes\AlertesPlateforme;
use Illuminate\Console\Command;

/**
 * Les établissements créés depuis l'app attendent la vérification de Ngoni
 * Santé (numéro d'autorisation, responsable) avant d'être visibles des
 * patients. Sans option : la liste de ceux qui attendent.
 *
 *     php artisan sante:etablissements-a-valider
 *     php artisan sante:etablissements-a-valider --valider=<uuid>
 */
class ValiderEtablissements extends Command
{
    protected $signature = 'sante:etablissements-a-valider {--valider= : uuid de l\'établissement vérifié}';

    protected $description = 'Liste les établissements en attente de validation, ou en valide un';

    public function handle(): int
    {
        $uuid = $this->option('valider');
        if (is_string($uuid) && $uuid !== '') {
            $etab = Etablissement::where('uuid', $uuid)->firstOrFail();
            $etab->update(['valide_at' => now()]);
            app(AlertesPlateforme::class)->etablissementValide($etab);
            $this->info("✓ {$etab->nom} est validé : il est visible des patients.");

            return self::SUCCESS;
        }

        $attente = Etablissement::whereNull('valide_at')->orderBy('created_at')->get();
        if ($attente->isEmpty()) {
            $this->info('Aucun établissement en attente.');

            return self::SUCCESS;
        }
        $this->table(['uuid', 'Nom', 'Profil', 'Autorisation', 'Responsable', 'Téléphone', 'Admin', 'Créé le'], $attente->map(fn (Etablissement $e): array => [
            $e->uuid, $e->nom, $e->profil, $e->autorisation ?? '—', $e->responsable ?? '—', $e->telephone ?? '—',
            User::where('etablissement_id', $e->id)->where('role', 'admin_etablissement')->value('telephone') ?? '—',
            $e->created_at?->format('d/m/Y H:i') ?? '—',
        ])->all());

        return self::SUCCESS;
    }
}
