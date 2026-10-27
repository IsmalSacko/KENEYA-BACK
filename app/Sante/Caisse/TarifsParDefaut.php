<?php

declare(strict_types=1);

namespace App\Sante\Caisse;

use App\Models\Etablissement;
use App\Models\Service;
use App\Models\Tarif;
use App\Sante\Profil;

/**
 * Les actes de départ d'un établissement, selon son secteur, au prix courant
 * à Bamako : la caisse facture dès le premier jour, l'établissement corrige
 * ses prix ensuite. Le ticket de consultation (et de CPN) suit son acte.
 */
final class TarifsParDefaut
{
    /**
     * Nom, catégorie, prix.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public static function pour(Profil $profil): array
    {
        return match ($profil) {
            Profil::CabinetMedical => [['Consultation', 'consultation', 5000], ['Pansement', 'soin', 1500], ['Injection', 'soin', 500]],
            Profil::CentreSante => [
                ['Consultation', 'consultation', 1500], ['CPN', 'acte', 1000], ['Accouchement', 'acte', 15000],
                ['Pansement', 'soin', 1000], ['Injection', 'soin', 300], ['Vaccination', 'soin', 0],
            ],
            Profil::Clinique => [
                ['Consultation', 'consultation', 10000], ['Consultation spécialiste', 'consultation', 15000], ['CPN', 'acte', 3000],
                ['Accouchement', 'acte', 50000], ['Césarienne', 'acte', 250000], ['Pansement', 'soin', 2500], ['Perfusion', 'soin', 5000],
            ],
            Profil::Maternite => [
                ['Consultation', 'consultation', 2000], ['CPN', 'acte', 1000], ['Accouchement', 'acte', 15000],
                ['Échographie obstétricale', 'acte', 7500], ['Vaccination', 'soin', 0],
            ],
            Profil::CabinetDentaire => [
                ['Consultation dentaire', 'consultation', 7500], ['Détartrage', 'acte', 15000], ['Extraction', 'acte', 10000],
                ['Plombage', 'acte', 20000],
            ],
            Profil::Laboratoire => [['Prélèvement', 'acte', 500]],
            // Les médicaments ont leur prix dans la pharmacie ; ici, les petits services.
            Profil::Pharmacie, Profil::DepotMedicaments => [['Prise de tension', 'soin', 500], ['Injection', 'soin', 500]],
        };
    }

    /** Une seule fois : un établissement qui a vidé ses tarifs ne les retrouve pas. */
    public static function creerSiAucun(Etablissement $etablissement): void
    {
        if (Tarif::withTrashed()->where('etablissement_id', $etablissement->id)->exists()) {
            return;
        }
        $profil = Profil::tryFrom((string) $etablissement->profil) ?? Profil::CabinetMedical;
        foreach (self::pour($profil) as [$nom, $categorie, $prix]) {
            Tarif::create(['etablissement_id' => $etablissement->id, 'nom' => $nom, 'categorie' => $categorie, 'prix' => $prix, 'actif' => true]);
        }
        self::lier($etablissement);
    }

    /** Le ticket du service Consultation porte l'acte de consultation ; celui de la Maternité, la CPN. */
    public static function lier(Etablissement $etablissement): void
    {
        foreach (['Consultation' => ['Consultation', 'Consultation dentaire'], 'Maternité' => ['CPN']] as $service => $noms) {
            $tarif = Tarif::where('etablissement_id', $etablissement->id)->whereIn('nom', $noms)->where('actif', true)->orderBy('id')->first();
            if ($tarif !== null) {
                Service::where('etablissement_id', $etablissement->id)->where('nom', $service)->whereNull('tarif_id')->update(['tarif_id' => $tarif->id]);
            }
        }
    }
}
