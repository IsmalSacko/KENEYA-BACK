<?php

declare(strict_types=1);

namespace App\Sante;

use RuntimeException;

/**
 * Un refus métier, dit à l'utilisateur tel quel (réponse 422) : « Ce lit est
 * occupé », « Code incorrect ou expiré »… Les contrôleurs n'attrapent que
 * lui : un 403 ou un 404 garde son code.
 */
final class Refus extends RuntimeException {}
