<?php

declare(strict_types=1);

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use App\Support\PlayerIdentity;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/games', static function (Request $request): JsonResponse {
    $id = bin2hex(random_bytes(16));
    $state = Atoms::get(MancalaGame::class, $id)->create(PlayerIdentity::for($request));
    Atoms::get(GameDirectory::class, GameDirectory::ID)->register($id, new DateTimeImmutable($state['expires_at']));

    return response()->json([
        'id' => $id,
        'url' => url('/games/' . $id),
        'expires_at' => $state['expires_at'],
        'state' => $state,
    ], 201);
});

// A browser cannot put an Authorization header on `new WebSocket(url)`, so it
// cannot reach the Worker's /ws on its own. Laravel holds the shared secret and
// knows which seat this session owns, so it mints a short-lived ticket scoped
// to one game and signs the seat key in as a claim. The Worker merges that
// claim over the browser's query params, which is what makes the seat
// unforgeable: asking for a ticket only ever gets you your own identity.
Route::post('/games/{game}/ticket', static function (Request $request, string $game): JsonResponse {
    $ticket = Atoms::ticket(MancalaGame::class, $game, [
        'client_id' => PlayerIdentity::for($request),
    ]);

    // The expiry is deliberately not published. The browser mints per attempt
    // rather than tracking a lifetime, and returning one invites a
    // proactive-refresh path the contract does not want.
    return response()->json([
        'url' => Atoms::wsUrl(MancalaGame::class, $game, [
            'channels' => ['game'],
            'mode' => $request->boolean('observe') ? 'observe' : 'player',
            'ticket' => (string) $ticket,
        ]),
    ]);
})->where('game', '[a-f0-9]{32}')
    ->middleware('throttle:tickets');

Route::get('/games/in-progress', static function (): JsonResponse {
    $directory = Atoms::get(GameDirectory::class, GameDirectory::ID);
    $candidateLimit = (int) config('mancala.discovery_candidates');
    $displayLimit = (int) config('mancala.discovery_limit');
    $games = [];

    foreach ($directory->randomActive($candidateLimit) as $gameId) {
        if (count($games) >= $displayLimit) {
            break;
        }

        try {
            $state = Atoms::get(MancalaGame::class, $gameId)->snapshot();
        } catch (Throwable) {
            continue;
        }

        if (($state['status'] ?? null) !== 'active') {
            $status = (string) ($state['status'] ?? 'expired');
            if (!in_array($status, ['waiting', 'finished', 'expired'], true)) {
                $status = 'expired';
            }
            $directory->updateStatus($gameId, $status);
            continue;
        }

        $games[] = [
            'id' => $gameId,
            'url' => url('/games/' . $gameId . '?observe=1'),
            'created_at' => $state['created_at'],
            'expires_at' => $state['expires_at'],
            'stores' => $state['stores'],
            'turn' => $state['turn'],
            'revision' => $state['revision'],
        ];
    }

    return response()->json(['games' => $games]);
});
