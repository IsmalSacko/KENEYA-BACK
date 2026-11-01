<?php

declare(strict_types=1);

namespace App\Sante\Apple;

use RuntimeException;

/** Un JWS que rien ne prouve signé par Apple : on n'en lit rien. */
class JwsInvalide extends RuntimeException {}
