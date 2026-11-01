<?php

declare(strict_types=1);

/*
 * Achat intégré Apple (application iPhone, StoreKit 2) : abonnements
 * auto-renouvelables. Les transactions et les notifications de l'App Store
 * sont des JWS signés par Apple, vérifiés ici jusqu'à sa racine (JwsApple).
 */
return [
    'bundle_id' => env('APPLE_BUNDLE_ID', 'com.ismaeldev.keneya'),

    /* Identifiant de l'application sur l'App Store : vérifié sur les notifications de production. */
    'app_apple_id' => (int) env('APPLE_APP_APPLE_ID', 6829558069),

    /*
     * Certificat racine d'Apple (Apple Root CA - G3), public, téléchargé sur
     * https://www.apple.com/certificateauthority/ ; empreinte SHA-256 vérifiée :
     * 63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179.
     */
    'racine' => env('APPLE_RACINE', resource_path('certificats/AppleRootCA-G3.cer')),

    /* Sandbox : achats de TestFlight et de la vérification d'Apple ; Production : l'App Store. */
    'environnements' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_ENVIRONNEMENTS', 'Sandbox,Production'))))),

    /*
     * Produits déclarés dans App Store Connect (un seul groupe d'abonnements)
     * => [formule, mois]. Au mois et à l'année sur l'iPhone, comme Ngoni
     * Caisse : les 3 et 6 mois restent aux autres moyens de paiement.
     */
    'produits' => [
        'ngonisante.essentiel.mensuel' => ['essentiel', 1],
        'ngonisante.essentiel.annuel' => ['essentiel', 12],
        'ngonisante.pro.mensuel' => ['pro', 1],
        'ngonisante.pro.annuel' => ['pro', 12],
        'ngonisante.hopital.mensuel' => ['hopital', 1],
        'ngonisante.hopital.annuel' => ['hopital', 12],
    ],
];
