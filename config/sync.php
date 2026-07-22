<?php

declare(strict_types=1);

use App\Models\Consultation;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\User;
use App\Models\VentePharmacieArticle;
use App\Models\VentesPharmacie;

/*
|--------------------------------------------------------------------------
| Registre de synchronisation offline
|--------------------------------------------------------------------------
|
| Décrit chaque table synchronisable. L'ORDRE compte : c'est l'ordre
| topologique d'application au push (parents avant enfants).
|
| Clés par table :
|  - model        : classe Eloquent.
|  - append_only  : true = create-only (insert-or-ignore par uuid, jamais de conflit).
|                   false = modifiable (Last-Write-Wins par updated_at).
|  - scope        : 'column' (colonne etablissement_id, défaut) | 'self'
|                   (etablissements : scopé par son propre id) | 'parent'
|                   (scopé via une clé étrangère parente, ex. articles de vente).
|  - fields       : champs modifiables par le client (whitelist mass-assignment).
|  - readonly     : champs envoyés au client mais jamais acceptés en écriture
|                   (ex. medicaments.stock, dérivé du ledger).
|  - fks          : ['colonne_id' => 'table_parente'] — résolues par uuid parent.
|                   Le client envoie 'colonne_uuid'.
|  - polymorphic  : référence polymorphe (type + uuid -> id).
|  - stock_effect : impact d'une ligne append-only sur medicaments.stock.
|  - hidden       : champs jamais exposés au pull (ex. pin).
|
*/

return [

    'tables' => [

        'etablissements' => [
            'model' => Etablissement::class,
            'append_only' => false,
            'scope' => 'self',
            'fields' => ['nom', 'type', 'telephone', 'actif', 'adresse', 'latitude', 'longitude'],
            'readonly' => [],
            'fks' => [],
        ],

        'users' => [
            'model' => User::class,
            'append_only' => false,
            'scope' => 'column',
            'fields' => ['name', 'telephone', 'role', 'avatar', 'actif', 'pin'],
            'readonly' => [],
            'hidden' => ['pin'],
            'fks' => [],
        ],

        'patients' => [
            'model' => Patient::class,
            'append_only' => false,
            'scope' => 'column',
            'fields' => ['nom', 'telephone', 'adresse'],
            'readonly' => [],
            'fks' => [],
        ],

        'medicaments' => [
            'model' => Medicament::class,
            'append_only' => false,
            'scope' => 'column',
            'fields' => ['nom', 'prix_unitaire', 'seuil_alerte', 'date_expiration', 'actif'],
            'readonly' => ['stock'],
            'fks' => [],
        ],

        'consultations' => [
            'model' => Consultation::class,
            'append_only' => true,
            'scope' => 'column',
            'fields' => ['motif', 'montant', 'statut'],
            'readonly' => [],
            'fks' => ['patient_id' => 'patients', 'medecin_id' => 'users'],
        ],

        'ventes_pharmacie' => [
            'model' => VentesPharmacie::class,
            'append_only' => true,
            'scope' => 'column',
            'fields' => ['montant_total', 'mode_paiement'],
            'readonly' => [],
            'fks' => ['patient_id' => 'patients'],
            'nullable_fks' => ['patient_id'],
        ],

        'vente_pharmacie_articles' => [
            'model' => VentePharmacieArticle::class,
            'append_only' => true,
            'scope' => 'parent',
            'scope_parent' => 'vente_pharmacie_id',
            'fields' => ['quantite', 'prix_unitaire', 'prix_total'],
            'readonly' => [],
            'fks' => ['vente_pharmacie_id' => 'ventes_pharmacie', 'medicament_id' => 'medicaments'],
            'stock_effect' => [
                'medicament_column' => 'medicament_id',
                'quantity_column' => 'quantite',
                'direction' => 'out',
            ],
        ],

        'mouvement_stocks' => [
            'model' => MouvementStock::class,
            'append_only' => true,
            'scope' => 'column',
            'fields' => ['type', 'quantite', 'commentaire'],
            'readonly' => [],
            'fks' => ['medicament_id' => 'medicaments'],
            'stock_effect' => [
                'medicament_column' => 'medicament_id',
                'quantity_column' => 'quantite',
                'direction' => 'by_type',
                'type_column' => 'type',
            ],
        ],

        'paiements' => [
            'model' => Paiement::class,
            'append_only' => true,
            'scope' => 'column',
            'fields' => ['montant', 'mode_paiement'],
            'readonly' => [],
            'fks' => [],
            'polymorphic' => [
                'type_column' => 'source_type',
                'uuid_column' => 'source_uuid',
                'id_column' => 'source_id',
                'nullable' => false,
                'map' => [
                    'consultation' => 'consultations',
                    'vente_pharmacie' => 'ventes_pharmacie',
                ],
            ],
        ],

        'journal_audits' => [
            'model' => JournalAudit::class,
            'append_only' => true,
            'scope' => 'column',
            'fields' => ['action', 'type_cible'],
            'readonly' => [],
            'fks' => ['user_id' => 'users'],
            'polymorphic' => [
                'type_column' => 'type_cible',
                'uuid_column' => 'cible_uuid',
                'id_column' => 'id_cible',
                'nullable' => true,
                'map' => [
                    'Patient' => 'patients',
                    'Consultation' => 'consultations',
                    'VentePharmacie' => 'ventes_pharmacie',
                    'Medicament' => 'medicaments',
                    'MouvementStock' => 'mouvement_stocks',
                    'Paiement' => 'paiements',
                ],
            ],
        ],

    ],

];
