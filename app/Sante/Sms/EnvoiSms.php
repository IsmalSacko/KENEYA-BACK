<?php

declare(strict_types=1);

namespace App\Sante\Sms;

/**
 * Envoi d'un SMS. Chaque pays aura sa passerelle derrière cette interface ;
 * en attendant, le pilote « log » écrit le message dans les journaux.
 */
interface EnvoiSms
{
    /**
     * @param  string  $telephone  numéro international (+22376123456)
     */
    public function envoyer(string $telephone, string $message): void;
}
