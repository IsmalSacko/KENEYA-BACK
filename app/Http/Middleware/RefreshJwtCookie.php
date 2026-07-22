<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renouvelle le JWT peu avant son expiration et le renvoie dans le cookie
 * HttpOnly « access_token ». À exécuter APRÈS auth:api.
 *
 * Effet : tant que l'utilisateur navigue, le token est régénéré → il n'expire
 * qu'après 24 h (JWT_TTL) d'inactivité.
 */
class RefreshJwtCookie
{
    /** Renouvelle si le token expire dans moins de X secondes. */
    private const REFRESH_WITHIN_SECONDS = 300;

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // On ne renouvelle le cookie que si l'auth s'est faite via cookie
        // (les clients Bearer, ex. Scramble/curl, ne sont pas impactés).
        if (! $request->hasCookie('access_token')) {
            return $response;
        }

        $guard = $this->auth->guard('api');

        try {
            $exp = $guard->payload()->get('exp');
        } catch (\Throwable) {
            return $response;
        }

        if (! is_numeric($exp)) {
            return $response;
        }

        // Pas encore proche de l'expiration → on évite un nouveau JWT (et une
        // entrée de blacklist) inutile.
        if ((int) $exp - now()->getTimestamp() > self::REFRESH_WITHIN_SECONDS) {
            return $response;
        }

        $token = $guard->refresh();

        /** @var string|null $domain */
        $domain = config('session.domain');

        return $response->withCookie(Cookie::create(
            name: 'access_token',
            value: $token,
            expire: now()->addMinutes(Config::integer('jwt.ttl')),
            path: '/',
            domain: $domain,
            secure: app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }
}
