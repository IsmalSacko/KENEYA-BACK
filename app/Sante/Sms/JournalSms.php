<?php

declare(strict_types=1);

namespace App\Sante\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Pilote de développement : le SMS est écrit dans les journaux, rien ne part.
 */
final class JournalSms implements EnvoiSms
{
    /** @var list<array{telephone: string, message: string}> */
    public array $envoyes = [];

    public function envoyer(string $telephone, string $message): void
    {
        $this->envoyes[] = ['telephone' => $telephone, 'message' => $message];
        Log::info('SMS (non envoyé, pilote journal)', ['telephone' => $telephone, 'message' => $message]);
    }
}
