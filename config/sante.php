<?php

declare(strict_types=1);

return [

    /*
    | SMS : pilote d'envoi. « journal » n'envoie rien (développement, ou tant
    | qu'aucune passerelle n'est branchée).
    */
    'sms' => [
        'pilote' => env('SANTE_SMS_PILOTE', 'journal'),
        // Vrai seulement quand une passerelle envoie vraiment : l'app propose
        // alors les codes SMS ; sinon WhatsApp, le QR et l'accueil.
        'actif' => env('SANTE_SMS_PILOTE', 'journal') !== 'journal',
    ],

    /*
    | Code SMS exigé à l'inscription. À activer dès qu'une passerelle SMS
    | réelle est branchée ; sans elle, personne ne recevrait son code.
    */
    'verification_telephone' => (bool) env('SANTE_VERIFICATION_TELEPHONE', false),

    /*
    | Graine de la paire de clés qui signe les ordonnances (QR vérifiable hors
    | ligne). Vide : dérivée de APP_KEY. Ne pas la changer en production, sinon
    | les ordonnances déjà imprimées ne se vérifient plus.
    */
    'ordonnances' => [
        'graine' => env('SANTE_ORDONNANCES_GRAINE'),
    ],

    'code' => [
        'longueur' => 6,
        'validite_minutes' => 10,
        'tentatives_max' => 5,
    ],

    // La Console Ngoni (toutes les applications) : sa clé secrète.
    'console' => [
        'cle' => env('SANTE_CLE_CONSOLE', ''),
    ],

    // Établissements qu'une personne peut créer (hors démonstrations). Chacun
    // a son propre abonnement ; la limite évite d'en recréer un à chaque fin
    // d'essai.
    'max_etablissements' => (int) env('SANTE_MAX_ETABLISSEMENTS', 3),

    // Abonnements : où l'établissement paie, comme Ngoni Caisse.
    // « +22370000000:orange_money,wave;+22365000000:moov_money »
    'abonnement' => [
        'numeros_paiement' => env('SANTE_NUMEROS_PAIEMENT', '+22373136789:orange_money,wave'),
        'whatsapp' => env('SANTE_WHATSAPP_SUPPORT', '+33605758494'),
    ],

];
