<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Conversions gardées mixed → scalaire, pour les valeurs issues de la base ou
 * des payloads JSON (typées mixed par PHPStan). Centralise les conversions
 * légitimes en un seul point, sans casts « aveugles ».
 */
final class Cast
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
