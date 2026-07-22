<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncPullRequest;
use App\Http\Requests\Sync\SyncPushRequest;
use App\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;

/** @tag Synchronisation offline */
class SyncController extends Controller
{
    public function __construct(private readonly SyncEngine $engine) {}

    /**
     * Renvoie les changements serveur depuis le curseur du client.
     */
    public function pull(SyncPullRequest $request): JsonResponse
    {
        $lastRev = $request->integer('last_pulled_rev');

        $tables = null;
        $raw = $request->input('tables');
        if (is_array($raw)) {
            /** @var list<string> $tables */
            $tables = array_values(array_filter($raw, 'is_string'));
        }

        $result = $this->engine->pull($this->currentUser(), $lastRev, $tables);

        return response()->json($result);
    }

    /**
     * Applique les changements locaux du client (upserts + suppressions),
     * de façon idempotente et scopée à l'établissement du JWT.
     */
    public function push(SyncPushRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $changes */
        $changes = (array) $request->input('changes', []);

        $result = $this->engine->push($this->currentUser(), $changes);

        return response()->json($result);
    }
}
