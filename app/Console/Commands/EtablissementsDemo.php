<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Agenda;
use App\Models\Assureur;
use App\Models\Chambre;
use App\Models\Dossier;
use App\Models\Etablissement;
use App\Models\Fournisseur;
use App\Models\Medicament;
use App\Models\Pays;
use App\Models\Personne;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\User;
use App\Sante\Dossier\Dossiers;
use App\Sante\File\ServicesParDefaut;
use App\Sante\Hospitalisation\Hospitalisations;
use App\Sante\Labo\Labo;
use App\Sante\Module;
use App\Sante\Pharmacie\Pharmacie;
use App\Sante\Profil;
use App\Sante\Telephone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Les établissements de démonstration, un par secteur, prêts à servir : le
 * personnel (un compte par métier), les services de la file, les tarifs,
 * les agendas, la pharmacie et ses lots, le catalogue du labo, les lits, les
 * assureurs, et quelques patients. Ismaila SACKO en est l'administrateur,
 * Aïcha NIAFO le médecin.
 *
 * Rejouable : ce qui existe déjà est gardé tel quel (rien n'est effacé, aucun
 * PIN n'est changé sauf avec --pins).
 */
class EtablissementsDemo extends Command
{
    protected $signature = 'sante:etablissements-demo {--pins : remet le PIN 1234 aux comptes de la démo}';

    protected $description = 'Crée (ou complète) les établissements de démonstration, un par secteur';

    private const PIN = '1234';

    private const ADMIN = ['73136789', 'Ismaila SACKO'];

    private const MEDECIN = ['74988201', 'Aïcha NIAFO'];

    /**
     * Un établissement par secteur : uuid fixe (la commande se rejoue sans
     * doublon), nom, adresse, position, personnel par métier.
     *
     * @var list<array{uuid: string, profil: Profil, nom: string, adresse: string, lat: float, lng: float, equipe: list<array{0: Role, 1: string}>}>
     */
    private const ETABLISSEMENTS = [
        [
            'uuid' => '6e0d1a10-0001-4c3a-9a51-0000000000a1', 'profil' => Profil::CabinetMedical,
            'nom' => 'Cabinet médical Ngoni (démo)', 'adresse' => 'Hamdallaye ACI 2000, Bamako', 'lat' => 12.6392, 'lng' => -8.0315,
            'equipe' => [[Role::Accueil, 'Fatoumata Coulibaly'], [Role::Infirmier, 'Moussa Diarra'], [Role::Caissier, 'Awa Konaté']],
        ],
        [
            'uuid' => '6e0d1a10-0002-4c3a-9a51-0000000000a2', 'profil' => Profil::CentreSante,
            'nom' => 'CSCom Ngoni (démo)', 'adresse' => 'Banconi, Bamako', 'lat' => 12.6740, 'lng' => -7.9790,
            'equipe' => [
                [Role::Accueil, 'Kadiatou Traoré'], [Role::Infirmier, 'Seydou Keïta'], [Role::SageFemme, 'Mariam Sidibé'],
                [Role::Pharmacien, 'Boubacar Touré'], [Role::Laborantin, 'Oumar Sangaré'], [Role::Caissier, 'Assétou Dembélé'],
                [Role::Gestionnaire, 'Ibrahim Maïga'],
            ],
        ],
        [
            'uuid' => '6e0d1a10-0003-4c3a-9a51-0000000000a3', 'profil' => Profil::Clinique,
            'nom' => 'Clinique Ngoni (démo)', 'adresse' => 'Badalabougou, Bamako', 'lat' => 12.6255, 'lng' => -7.9950,
            'equipe' => [
                [Role::Accueil, 'Aminata Cissé'], [Role::Infirmier, 'Adama Coulibaly'], [Role::SageFemme, 'Hawa Diallo'],
                [Role::Pharmacien, 'Mamadou Kané'], [Role::Laborantin, 'Bakary Samaké'], [Role::Caissier, 'Rokia Bagayoko'],
                [Role::Gestionnaire, 'Souleymane Doumbia'],
            ],
        ],
        [
            'uuid' => '6e0d1a10-0004-4c3a-9a51-0000000000a4', 'profil' => Profil::Pharmacie,
            'nom' => 'Pharmacie Ngoni (démo)', 'adresse' => 'Lafiabougou, Bamako', 'lat' => 12.6450, 'lng' => -8.0480,
            'equipe' => [[Role::Pharmacien, 'Djénéba Sylla'], [Role::Caissier, 'Lassana Diakité']],
        ],
        [
            'uuid' => '6e0d1a10-0005-4c3a-9a51-0000000000a5', 'profil' => Profil::DepotMedicaments,
            'nom' => 'Dépôt de médicaments Ngoni (démo)', 'adresse' => 'Kati', 'lat' => 12.7440, 'lng' => -8.0730,
            'equipe' => [[Role::Pharmacien, 'Sidi Coulibaly'], [Role::Caissier, 'Nana Traoré']],
        ],
        [
            'uuid' => '6e0d1a10-0006-4c3a-9a51-0000000000a6', 'profil' => Profil::Laboratoire,
            'nom' => 'Laboratoire Ngoni (démo)', 'adresse' => 'Hippodrome, Bamako', 'lat' => 12.6580, 'lng' => -7.9900,
            'equipe' => [[Role::Accueil, 'Salimata Koné'], [Role::Laborantin, 'Yacouba Fofana'], [Role::Caissier, 'Kadidia Sow']],
        ],
        [
            'uuid' => '6e0d1a10-0007-4c3a-9a51-0000000000a7', 'profil' => Profil::Maternite,
            'nom' => 'Maternité Ngoni (démo)', 'adresse' => 'Sabalibougou, Bamako', 'lat' => 12.6020, 'lng' => -7.9820,
            'equipe' => [
                [Role::Accueil, 'Oumou Sanogo'], [Role::SageFemme, 'Fanta Camara'], [Role::Infirmier, 'Aïssata Ballo'],
                [Role::Caissier, 'Modibo Keïta'],
            ],
        ],
        [
            'uuid' => '6e0d1a10-0008-4c3a-9a51-0000000000a8', 'profil' => Profil::CabinetDentaire,
            'nom' => 'Cabinet dentaire Ngoni (démo)', 'adresse' => 'Djélibougou, Bamako', 'lat' => 12.6610, 'lng' => -7.9650,
            'equipe' => [[Role::Accueil, 'Sira Diabaté'], [Role::Infirmier, 'Drissa Togola'], [Role::Caissier, 'Bintou Kouyaté']],
        ],
    ];

    /** Produits de la pharmacie : nom, DCI, forme, unités par boîte, prix boîte, prix à l'unité, sur ordonnance. */
    private const PRODUITS = [
        ['Paracétamol 500 mg', 'Paracétamol', 'comprimé', 10, 500, 50, false],
        ['Amoxicilline 500 mg', 'Amoxicilline', 'gélule', 12, 1500, 125, true],
        ['Coartem 20/120', 'Artéméther + luméfantrine', 'comprimé', 24, 2500, null, false],
        ['Métronidazole 250 mg', 'Métronidazole', 'comprimé', 20, 1000, 50, true],
        ['Cotrimoxazole 480 mg', 'Sulfaméthoxazole + triméthoprime', 'comprimé', 20, 750, 40, true],
        ['Ibuprofène 400 mg', 'Ibuprofène', 'comprimé', 10, 750, 75, false],
        ['SRO sachet', 'Sels de réhydratation orale', 'sachet', 1, 150, null, false],
        ['Fer + acide folique', 'Fer + acide folique', 'comprimé', 30, 600, 20, false],
        ['Sulfadoxine-pyriméthamine', 'Sulfadoxine + pyriméthamine', 'comprimé', 3, 300, null, true],
        ['Oméprazole 20 mg', 'Oméprazole', 'gélule', 14, 1200, null, false],
        ['Seringue 5 ml', null, 'unité', 1, 100, null, false],
        ['Gants d’examen', null, 'paire', 1, 100, null, false],
    ];

    public function handle(Dossiers $dossiers, Labo $labo, Pharmacie $pharmacie, Hospitalisations $hospit): int
    {
        $pays = Pays::findOrFail('ML');
        $avecPins = (bool) $this->option('pins');

        $admin = $this->personne(self::ADMIN[0], self::ADMIN[1], $pays, $avecPins);
        $medecin = $this->personne(self::MEDECIN[0], self::MEDECIN[1], $pays, $avecPins);

        foreach (self::ETABLISSEMENTS as $rang => $e) {
            DB::transaction(function () use ($e, $rang, $pays, $admin, $medecin, $avecPins, $dossiers, $labo, $pharmacie, $hospit): void {
                $etab = Etablissement::firstOrCreate(['uuid' => $e['uuid']], [
                    'nom' => $e['nom'], 'profil' => $e['profil']->value, 'pays_code' => $pays->code, 'devise' => $pays->devise,
                    'adresse' => $e['adresse'], 'latitude' => $e['lat'], 'longitude' => $e['lng'],
                    'telephone' => '+2232022'.str_pad((string) ($rang + 1), 4, '0', STR_PAD_LEFT), 'actif' => true,
                ]);
                $a = static fn (Module $m): bool => in_array($m->value, $etab->modules, true);

                // L'équipe : l'admin, le médecin là où l'on consulte, un compte par métier.
                $this->membre($etab, $admin, Role::AdminEtablissement);
                $docteur = $a(Module::Consultation) ? $this->membre($etab, $medecin, Role::Medecin) : null;
                $equipe = [];
                foreach ($e['equipe'] as $i => [$role, $nom]) {
                    $numero = '7000'.str_pad((string) ($rang + 1), 2, '0', STR_PAD_LEFT).str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                    $equipe[$role->value] = $this->membre($etab, $this->personne($numero, $nom, $pays, $avecPins), $role);
                }

                ServicesParDefaut::creerSiAucun($etab);
                $this->tarifs($etab, $e['profil']);
                $this->agendas($etab, $e['profil'], $docteur, $equipe);
                if ($a(Module::Labo)) {
                    $this->labo($etab, $labo);
                }
                if ($a(Module::Pharmacie)) {
                    $this->pharmacie($etab, $pharmacie);
                }
                if ($a(Module::Hospitalisation)) {
                    $this->lits($etab, $hospit);
                }
                if ($a(Module::Assurances)) {
                    foreach ([['AMO (CANAM)', 70], ['INPS', 80], ['Mutuelle MUTEC', 50]] as [$nom, $taux]) {
                        Assureur::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => $nom], ['taux' => $taux, 'actif' => true]);
                    }
                }
                if ($a(Module::Consultation) || $a(Module::Labo)) {
                    $this->patients($etab, $dossiers);
                }
            });
            $this->line("✓ {$e['nom']}");
        }

        $this->newLine();
        $this->info('Connexion : '.self::ADMIN[0].' (admin) et '.self::MEDECIN[0].' (médecin), PIN '.self::PIN.'. Le personnel : 7000 + établissement + rang (ex. 70000201), même PIN.');

        return self::SUCCESS;
    }

    /** Le compte Ngoni Santé (un numéro) ; son nom et son PIN ne sont posés qu'à la création, ou avec --pins. */
    private function personne(string $numero, string $nom, Pays $pays, bool $avecPins): Personne
    {
        $telephone = Telephone::normaliser($numero, $pays);
        $personne = Personne::withTrashed()->firstOrNew(['telephone' => $telephone]);
        if (! $personne->exists || $avecPins) {
            $personne->fill(['nom' => $nom, 'pays_code' => $pays->code, 'pin' => self::PIN, 'actif' => true]);
            $personne->telephone_verifie_at ??= now();
        }
        $personne->deleted_at = null;
        $personne->save();

        return $personne;
    }

    /** L'espace pro de la personne dans l'établissement, avec ce rôle. */
    private function membre(Etablissement $etab, Personne $personne, Role $role): User
    {
        $membre = User::where('etablissement_id', $etab->id)->where('telephone', $personne->telephone)->first();
        if ($membre === null) {
            $membre = new User([
                'etablissement_id' => $etab->id, 'name' => $personne->nom, 'telephone' => $personne->telephone,
                'role' => $role->value, 'actif' => true,
            ]);
            $membre->setAttribute('personne_id', $personne->id);
            // Le membre garde le PIN de la personne, comme à la création d'un établissement.
            $membre->setRawAttributes(array_merge($membre->getAttributes(), ['pin' => $personne->pin]));
            $membre->save();
            $membre->etablissements()->syncWithoutDetaching([$etab->id]);
        }

        return $membre;
    }

    /** Les actes du secteur ; le ticket du service suit son acte (consultation, CPN). */
    private function tarifs(Etablissement $etab, Profil $profil): void
    {
        $actes = match ($profil) {
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
            Profil::Pharmacie, Profil::DepotMedicaments => [],
        };
        foreach ($actes as [$nom, $categorie, $prix]) {
            Tarif::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => $nom], ['categorie' => $categorie, 'prix' => $prix, 'actif' => true]);
        }

        foreach (['Consultation' => ['Consultation', 'Consultation dentaire'], 'Maternité' => ['CPN']] as $service => $noms) {
            $tarif = Tarif::where('etablissement_id', $etab->id)->whereIn('nom', $noms)->orderBy('id')->first();
            if ($tarif !== null) {
                Service::where('etablissement_id', $etab->id)->where('nom', $service)->whereNull('tarif_id')->update(['tarif_id' => $tarif->id]);
            }
        }
    }

    /**
     * Les agendas où l'on prend rendez-vous, du lundi au vendredi (et le
     * samedi matin), à confirmer par l'équipe.
     *
     * @param  array<string, User>  $equipe
     */
    private function agendas(Etablissement $etab, Profil $profil, ?User $docteur, array $equipe): void
    {
        $agendas = array_values(array_filter([
            $docteur !== null ? ['Consultation — Dr Aïcha NIAFO', 'Consultation', $docteur, 20] : null,
            isset($equipe[Role::Infirmier->value]) ? ['Soins infirmiers', null, $equipe[Role::Infirmier->value], 15] : null,
            isset($equipe[Role::SageFemme->value]) ? ['CPN — Sage-femme', 'Maternité', $equipe[Role::SageFemme->value], 30] : null,
            $profil === Profil::Laboratoire || $profil === Profil::CentreSante
                ? ['Prélèvements', 'Laboratoire', $equipe[Role::Laborantin->value] ?? null, 10] : null,
        ]));

        foreach ($agendas as [$nom, $service, $membre, $duree]) {
            $agenda = Agenda::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => $nom], [
                'service_id' => $service !== null ? Service::where('etablissement_id', $etab->id)->where('nom', $service)->value('id') : null,
                'membre_id' => $membre?->id,
                'duree_creneau_min' => $duree, 'places_par_creneau' => 1,
                'reservation_en_ligne' => true, 'validation_auto' => false,
                'delai_min_heures' => 1, 'horizon_jours' => 60, 'actif' => true,
            ]);
            if ($agenda->wasRecentlyCreated) {
                foreach (range(1, 5) as $jour) {
                    $agenda->horaires()->create(['jour' => $jour, 'debut' => '08:00', 'fin' => '12:30']);
                    $agenda->horaires()->create(['jour' => $jour, 'debut' => '15:00', 'fin' => '17:30']);
                }
                $agenda->horaires()->create(['jour' => 6, 'debut' => '08:00', 'fin' => '12:00']);
            }
        }
    }

    /** Le catalogue du labo, avec les prix des analyses courantes. */
    private function labo(Etablissement $etab, Labo $labo): void
    {
        $prix = ['TDR' => 1000, 'GE' => 1000, 'HB' => 1000, 'NFS' => 3500, 'GLY' => 1500, 'CREA' => 2500, 'GRP' => 2000,
            'BHCG' => 1500, 'VIH' => 0, 'WIDAL' => 3000, 'ALB' => 500, 'ECBU' => 5000, 'KAOP' => 1500];
        foreach ($labo->catalogue($etab) as $analyse) {
            $montant = $prix[$analyse->code] ?? 0;
            if ($analyse->tarif_id === null && $montant > 0) {
                $labo->enregistrer($etab, ['prix' => $montant], $analyse);
            }
        }
    }

    /** Les produits courants, reçus du grossiste en lots ; un lot qui périme bientôt, pour voir l'alerte. */
    private function pharmacie(Etablissement $etab, Pharmacie $pharmacie): void
    {
        $grossiste = Fournisseur::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => 'Laborex Mali'], ['telephone' => '+22320222222']);
        Fournisseur::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => 'PPM'], ['telephone' => '+22320223333']);

        foreach (self::PRODUITS as $i => [$nom, $dci, $forme, $boite, $prix, $prixUnite, $ordonnance]) {
            $produit = Medicament::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => $nom], [
                'dci' => $dci, 'forme' => $forme, 'conditionnement' => $boite, 'prix_unitaire' => $prix,
                'vente_unite' => $prixUnite !== null, 'prix_unite' => $prixUnite, 'ordonnance_requise' => $ordonnance,
                'seuil_alerte' => $boite * 5, 'stock' => 0, 'actif' => true,
            ]);
            if (! $produit->wasRecentlyCreated) {
                continue;
            }
            $pharmacie->recevoir($etab, $grossiste, [[
                'medicament' => $produit,
                'numero' => 'L'.now()->format('y').str_pad((string) (101 + $i), 4, '0', STR_PAD_LEFT),
                'peremption' => now()->addMonths($i === 1 ? 2 : 18 + $i)->startOfMonth()->toDateString(),
                // Le Fer + acide folique arrive en petite quantité : l'alerte de stock bas se voit.
                'quantite' => $boite * ($nom === 'Fer + acide folique' ? 3 : 20),
                'prix_achat' => round($prix * 0.7),
            ]]);
        }
    }

    private function lits(Etablissement $etab, Hospitalisations $hospit): void
    {
        foreach ([['Chambre 1', 'Médecine', 2, 15000], ['Chambre 2', 'Pédiatrie', 3, 10000], ['Salle commune', 'Médecine', 6, 5000], ['Suites de couches', 'Maternité', 4, 7500]] as [$nom, $service, $lits, $prix]) {
            if (! Chambre::where('etablissement_id', $etab->id)->where('nom', $nom)->exists()) {
                $hospit->creerChambre($etab, ['nom' => $nom, 'service' => $service, 'lits' => $lits, 'prix' => $prix]);
            }
        }
    }

    /** Quelques patients déjà enregistrés, avec leur numéro de dossier. */
    private function patients(Etablissement $etab, Dossiers $dossiers): void
    {
        $patients = [
            ['Awa Traoré', 'F', '1994-03-12', 'Banconi'], ['Moussa Coulibaly', 'M', '1971-07-02', 'Hamdallaye'],
            ['Fanta Keïta', 'F', '2018-11-20', 'Lafiabougou'], ['Bakary Sanogo', 'M', '1985-01-15', 'Djélibougou'],
            ['Oumou Diallo', 'F', '1999-09-05', 'Sabalibougou'], ['Ibrahim Konaté', 'M', '2024-05-30', 'Banconi'],
        ];
        foreach ($patients as [$nom, $sexe, $naissance, $quartier]) {
            $dossier = Dossier::firstOrCreate(['etablissement_id' => $etab->id, 'nom' => $nom], [
                'sexe' => $sexe, 'date_naissance' => $naissance, 'naissance_estimee' => false, 'adresse' => $quartier,
            ]);
            $dossiers->numero($dossier, $etab);
        }
    }
}
