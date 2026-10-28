<?php

declare(strict_types=1);

use App\Http\Middleware\AuthAppareil;
use App\Http\Middleware\CleConsole;
use App\Http\Middleware\ExigeDroit;
use App\Http\Middleware\ExigeFonction;
use App\Sante\Refus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Derrière le reverse-proxy (Traefik/nginx) qui termine le TLS : faire
        // confiance aux en-têtes X-Forwarded-* pour que Laravel détecte HTTPS
        // et génère des URLs absolues en https:// (formulaires, liens, etc.).
        $middleware->trustProxies(at: '*');

        $middleware->api([
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'droit' => ExigeDroit::class,
            'appareil' => AuthAppareil::class,
            'fonction' => ExigeFonction::class,
            'cle-console' => CleConsole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un refus métier (« Votre formule permet 5 membres… ») : 422 et son message.
        $exceptions->render(fn (Refus $e) => response()->json(['message' => $e->getMessage()], 422));
    })->create();
