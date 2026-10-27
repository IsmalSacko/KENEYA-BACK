<?php

declare(strict_types=1);

return [

    /*
    | SMS : pilote d'envoi. « journal » n'envoie rien (développement, ou tant
    | qu'aucune passerelle n'est branchée).
    */
    'sms' => [
        'pilote' => env('SANTE_SMS_PILOTE', 'journal'),
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

];
