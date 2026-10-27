<?php

declare(strict_types=1);

namespace App\Sante;

use App\Sante\Sms\EnvoiSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Codes à usage unique envoyés par SMS : vérifier un numéro à l'inscription,
 * choisir un nouveau PIN quand on l'a oublié.
 */
final class CodesVerification
{
    public const INSCRIPTION = 'inscription';

    public const PIN = 'pin';

    /** Le patient autorise un établissement à voir son dossier. */
    public const DOSSIER = 'dossier';

    public function __construct(private readonly EnvoiSms $sms) {}

    public function envoyer(string $telephone, string $objet): void
    {
        $longueur = $this->entier('sante.code.longueur', 6);
        $code = str_pad((string) random_int(0, 10 ** $longueur - 1), $longueur, '0', STR_PAD_LEFT);

        // Un seul code valable à la fois par numéro et par objet.
        DB::table('codes_verification')
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->whereNull('utilise_at')
            ->delete();

        DB::table('codes_verification')->insert([
            'telephone' => $telephone,
            'objet' => $objet,
            'code' => Hash::make($code),
            'expire_at' => now()->addMinutes($this->entier('sante.code.validite_minutes', 10)),
            'tentatives' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sms->envoyer($telephone, $objet === self::DOSSIER
            ? "Ngoni Santé : code {$code} pour laisser le soignant qui vous reçoit voir votre dossier. Ne le donnez qu'à lui."
            : "Ngoni Santé : votre code est {$code}. Ne le donnez à personne.");
    }

    /**
     * Vrai si le code est bon ; il est alors consommé.
     */
    public function verifier(string $telephone, string $objet, string $code): bool
    {
        $ligne = DB::table('codes_verification')
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->whereNull('utilise_at')
            ->where('expire_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($ligne === null || (int) $ligne->tentatives >= $this->entier('sante.code.tentatives_max', 5)) {
            return false;
        }

        if (! Hash::check($code, (string) $ligne->code)) {
            DB::table('codes_verification')->where('id', $ligne->id)->increment('tentatives');

            return false;
        }

        DB::table('codes_verification')->where('id', $ligne->id)->update(['utilise_at' => now()]);

        return true;
    }

    private function entier(string $cle, int $defaut): int
    {
        $valeur = config($cle, $defaut);

        return is_numeric($valeur) ? (int) $valeur : $defaut;
    }
}
