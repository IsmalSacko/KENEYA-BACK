<?php

declare(strict_types=1);

namespace App\Sante\File;

use App\Models\Etablissement;
use App\Models\PointService;
use App\Models\Service;
use App\Sante\Module;

/**
 * Les services de départ d'un établissement, selon ses modules : la file
 * fonctionne dès le premier jour, sans rien régler.
 */
final class ServicesParDefaut
{
    public static function creerSiAucun(Etablissement $etablissement): void
    {
        if (Service::withTrashed()->where('etablissement_id', $etablissement->id)->exists()) {
            return;
        }

        $modules = $etablissement->modules;
        $a = static fn (Module $m): bool => in_array($m->value, $modules, true);

        $services = array_values(array_filter([
            $a(Module::Consultation) ? ['Consultation', 'A', 15, 'Salle 1'] : null,
            $a(Module::Maternite) ? ['Maternité', 'M', 20, 'Salle maternité'] : null,
            $a(Module::Labo) ? ['Laboratoire', 'L', 10, 'Prélèvement'] : null,
            $a(Module::Caisse) ? ['Caisse', 'C', 3, 'Guichet 1'] : null,
            $a(Module::Pharmacie) ? ['Pharmacie', 'P', 5, 'Comptoir 1'] : null,
        ]));

        foreach ($services as $ordre => [$nom, $prefixe, $duree, $point]) {
            $service = Service::create([
                'etablissement_id' => $etablissement->id,
                'nom' => $nom,
                'prefixe' => $prefixe,
                'duree_moyenne_min' => $duree,
                'ordre' => $ordre,
            ]);
            PointService::create([
                'etablissement_id' => $etablissement->id,
                'service_id' => $service->id,
                'nom' => $point,
            ]);
        }
    }
}
