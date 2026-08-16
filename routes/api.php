<?php

declare(strict_types=1);

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use App\Support\PlayerIdentity;
use Atoms\Client\Exception\TicketAcquisitionFailed;
use Atoms\Client\Tickets\TicketClient;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/games', static function (Request $request): JsonResponse {
    $id = bin2hex(random_bytes(16));
    $createdAt = new DateTimeImmutable();
    $expiresAt = $createdAt->modify('+' . (int) config('mancala.game_lifetime_hours') . ' hours');
    $state = Atoms::get(MancalaGame::class, $id)->create(
        PlayerIdentity::for($request),
        $createdAt,
        $expiresAt,
    );
    Atoms::get(GameDirectory::class, GameDirectory::ID)->register($id, $createdAt, $expiresAt);

    return response()->json([
        'id' => $id,
        'url' => url('/games/' . $id),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'state' => $state,
    ], 201);
});

// A browser cannot put an Authorization header on `new WebSocket(url)`, so it
// cannot reach the Worker's /ws on its own. Laravel holds the shared secret and
// knows which seat this session owns, so it mints a short-lived ticket scoped
// to one game and signs the seat key in as a claim. The Worker merges that
// claim over the browser's query params, which is what makes the seat
// unforgeable: asking for a ticket only ever gets you your own identity.
Route::post('/games/{game}/ticket', static function (
    Request $request,
    TicketClient $tickets,
    string $game,
): JsonResponse {
    try {
        $ticket = $tickets->acquire('MancalaGame', $game, [
            'client_id' => PlayerIdentity::for($request),
        ]);
    } catch (TicketAcquisitionFailed $failure) {
        // Log it before swallowing: a browser cannot read why a WebSocket
        // upgrade failed, so this line is the only place a secret that has
        // drifted from the Worker's is diagnosable.
        report($failure);

        // The caller gets nothing to branch on, because there is nothing useful
        // it could do differently. The contract on any failure is "mint again".
        return response()->json(['message' => 'Could not reach the game right now.'], 503);
    }

    // The expiry is deliberately not returned. The browser mints per attempt
    // rather than tracking a lifetime, and publishing one invites a
    // proactive-refresh path the contract does not want.
    return response()->json(['ticket' => (string) $ticket]);
})->where('game', '[a-f0-9]{32}')
    ->middleware('throttle:tickets');

Route::get('/games/in-progress', static function (): JsonResponse {
    $directory = Atoms::get(GameDirectory::class, GameDirectory::ID);
    $now = new DateTimeImmutable();
    $candidateLimit = (int) config('mancala.discovery_candidates');
    $displayLimit = (int) config('mancala.discovery_limit');
    $candidates = $directory->randomActive($now, $candidateLimit);
    $games = [];

    foreach ($candidates as $candidate) {
        if (count($games) >= $displayLimit) {
            break;
        }

        try {
            $state = Atoms::get(MancalaGame::class, (string) $candidate['game_id'])->snapshot();
        } catch (Throwable) {
            continue;
        }

        if (($state['status'] ?? null) !== 'active') {
            $status = (string) ($state['status'] ?? 'expired');
            if (!in_array($status, ['waiting', 'finished', 'expired'], true)) {
                $status = 'expired';
            }
            $directory->updateStatus((string) $candidate['game_id'], $status, $now);
            continue;
        }

        $games[] = [
            'id' => $candidate['game_id'],
            'url' => url('/games/' . $candidate['game_id'] . '?observe=1'),
            'created_at' => $state['created_at'],
            'expires_at' => $state['expires_at'],
            'stores' => $state['stores'],
            'turn' => $state['turn'],
            'revision' => $state['revision'],
        ];
    }

    return response()->json(['games' => $games]);
});
