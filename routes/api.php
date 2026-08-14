<?php

declare(strict_types=1);

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use Atoms\Laravel\Facades\Atoms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/games', static function (Request $request): JsonResponse {
    $input = $request->validate([
        'client_id' => ['required', 'string', 'max:' . (int) config('mancala.client_id_max_bytes')],
    ]);

    $id = bin2hex(random_bytes(16));
    $createdAt = new DateTimeImmutable();
    $expiresAt = $createdAt->modify('+' . (int) config('mancala.game_lifetime_hours') . ' hours');
    $state = Atoms::get(MancalaGame::class, $id)->create($input['client_id'], $createdAt, $expiresAt);
    Atoms::get(GameDirectory::class, GameDirectory::ID)->register($id, $createdAt, $expiresAt);

    return response()->json([
        'id' => $id,
        'url' => url('/games/' . $id),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'state' => $state,
    ], 201);
});

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
