<?php

declare(strict_types=1);

namespace App\Sante\Notifications;

use App\Models\Personne;
use App\Models\TelephonePush;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifications sur le téléphone, par Firebase Cloud Messaging (API v1), avec
 * la clé du compte de service du projet Firebase partagé avec Ngoni Caisse
 * (SANTE_FIREBASE_CREDENTIALS). Sans clé, rien ne part.
 */
final class PushFirebase
{
    /**
     * @param  array<string, string>  $donnees  pour l'app (ex. lien à ouvrir)
     * @return int téléphones atteints
     */
    public function aLaPersonne(Personne $personne, string $titre, string $texte, array $donnees = []): int
    {
        $reussis = 0;
        foreach (TelephonePush::where('personne_id', $personne->id)->get() as $telephone) {
            $reussis += $this->envoyer($telephone->jeton, $titre, $texte, $donnees) ? 1 : 0;
        }

        return $reussis;
    }

    /** @param  array<string, string>  $donnees */
    public function envoyer(string $jeton, string $titre, string $texte, array $donnees = []): bool
    {
        $identifiants = $this->identifiants();
        if ($identifiants === null) {
            return false;
        }

        try {
            $reponse = Http::withToken($this->jetonAcces($identifiants))->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$identifiants['project_id']}/messages:send", ['message' => [
                    'token' => $jeton,
                    'notification' => ['title' => $titre, 'body' => $texte],
                    'data' => $donnees === [] ? new \stdClass : $donnees,
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'ngoni_sante']],
                ]]);
        } catch (\Throwable $e) {
            Log::warning('Push non envoyé : '.$e->getMessage());

            return false;
        }

        return $this->traiter($jeton, $reponse);
    }

    private function traiter(string $jeton, Response $reponse): bool
    {
        if ($reponse->successful()) {
            return true;
        }
        // App désinstallée ou jeton périmé : on l'oublie.
        if ($reponse->status() === 404 || str_contains($reponse->body(), 'UNREGISTERED')) {
            TelephonePush::where('jeton', $jeton)->delete();
        } else {
            Log::warning('Push refusé', ['statut' => $reponse->status(), 'corps' => mb_substr($reponse->body(), 0, 300)]);
        }

        return false;
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    private function identifiants(): ?array
    {
        $chemin = config()->string('sante.push.credentials', '');
        if ($chemin === '' || ! is_readable($chemin)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($chemin), true);
        if (! is_array($d) || ! is_string($d['project_id'] ?? null) || ! is_string($d['client_email'] ?? null) || ! is_string($d['private_key'] ?? null)) {
            return null;
        }

        return ['project_id' => $d['project_id'], 'client_email' => $d['client_email'], 'private_key' => $d['private_key']];
    }

    /** @param  array{project_id: string, client_email: string, private_key: string}  $id */
    private function jetonAcces(array $id): string
    {
        return Cache::remember('sante-firebase-jeton-acces', 50 * 60, function () use ($id): string {
            $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $maintenant = time();
            $entete = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $charge = $b64((string) json_encode([
                'iss' => $id['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $maintenant,
                'exp' => $maintenant + 3600,
            ]));
            openssl_sign("{$entete}.{$charge}", $signature, $id['private_key'], OPENSSL_ALGO_SHA256);

            $jeton = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$entete}.{$charge}.".$b64((string) $signature),
            ])->throw()->json('access_token');

            return is_string($jeton) ? $jeton : '';
        });
    }
}
