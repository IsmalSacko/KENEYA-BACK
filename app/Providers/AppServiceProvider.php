<?php

namespace App\Providers;

use App\Models\Etablissement;
use App\Models\User;
use App\Policies\EtablissementPolicy;
use App\Policies\UserPolicy;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use Tymon\JWTAuth\Http\Parser\AuthHeaders;
use Tymon\JWTAuth\Http\Parser\Cookies;
use Tymon\JWTAuth\Http\Parser\Parser;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Etablissement::class => EtablissementPolicy::class,
        User::class => UserPolicy::class,
    ];



    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Déclare l'auth Bearer JWT dans la doc Scramble → bouton "Authorize".
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT')
                );
            });

        // Le JWT est lu soit depuis l'en-tête Authorization (Scramble/curl),
        // soit depuis le cookie HttpOnly « access_token » (navigation web).
        $this->app->booted(function (): void {
            /** @var Parser $parser */
            $parser = $this->app->make('tymon.jwt.parser');

            $parser->setChain([
                new AuthHeaders(),
                (new Cookies((bool) config('jwt.decrypt_cookies')))
                    ->setKey('access_token'),
            ]);
        });
    }
}
