<?php

declare(strict_types=1);

namespace App\Providers;

use App\Sante\Sms\EnvoiSms;
use App\Sante\Sms\JournalSms;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Seul pilote pour l'instant ; les passerelles par pays viendront ici
        // selon config('sante.sms.pilote').
        $this->app->singleton(EnvoiSms::class, JournalSms::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->limiteurs();

        // Déclare l'auth Bearer JWT dans la doc Scramble → bouton "Authorize".
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                );
            });
    }

    /**
     * Limites de débit nommées : chacune a son propre compteur. Avec
     * `throttle:10,1`, toutes les routes partageaient un seul compteur par
     * adresse IP ; or en Afrique beaucoup d'abonnés mobiles sortent par la
     * même IP d'opérateur, et un écran de salle qui interroge l'API toutes
     * les 3 s épuisait le quota de tout l'établissement.
     */
    private function limiteurs(): void
    {
        $ip = static fn (Request $r): string => (string) $r->ip();

        RateLimiter::for('referentiel', fn (Request $r) => Limit::perMinute(120)->by($ip($r)));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($ip($r)));
        // Large par IP (NAT d'opérateur) ; le PIN a en plus sa limite par numéro.
        RateLimiter::for('compte', fn (Request $r) => Limit::perMinute(60)->by($ip($r)));
        RateLimiter::for('sms', fn (Request $r) => [
            Limit::perMinute(20)->by($ip($r)),
            Limit::perHour(5)->by('tel:'.$r->string('pays_code')->toString().$r->string('telephone')->toString()),
        ]);
        RateLimiter::for('appairage', fn (Request $r) => Limit::perMinute(20)->by($ip($r)));
        // Un écran interroge toutes les 3 s : compté par appareil, pas par IP.
        RateLimiter::for('appareil', fn (Request $r) => Limit::perMinute(120)->by('appareil:'.(string) $r->header('X-Appareil')));
        RateLimiter::for('web', fn (Request $r) => Limit::perMinute(60)->by($ip($r)));
    }
}
