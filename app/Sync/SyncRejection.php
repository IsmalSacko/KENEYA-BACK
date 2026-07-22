<?php

declare(strict_types=1);

namespace App\Sync;

use RuntimeException;

/**
 * Rejet métier d'un enregistrement pendant un push. Non fatal : l'enregistrement
 * est ignoré et signalé au client (qui le rejouera), les autres continuent.
 */
class SyncRejection extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
