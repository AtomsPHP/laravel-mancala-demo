<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\GameDirectory\Support\GameListing;
use App\Atoms\Shared\GameStatus;
use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;

/**
 * A tiny durable index of public games; each game still owns its own state.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
class GameDirectory extends Atom
{
    public const ID = 'public-mancala-lobby';

    protected function onActivation(): void
    {
        EloquentBridge::boot($this->db());
    }

    public function register(string $gameId, \DateTimeImmutable $expiresAt): void
    {
        GameListing::query()->updateOrCreate(
            ['game_id' => $gameId],
            ['status' => GameStatus::Waiting, 'expires_at' => $expiresAt],
        );
    }

    public function updateStatus(string $gameId, string $status, ?\DateTimeImmutable $expiresAt = null): void
    {
        GameListing::query()->find($gameId)?->update(array_filter([
            'status' => GameStatus::from($status),
            'expires_at' => $expiresAt,
        ]));
    }

    /** @return array<int, string> */
    public function randomActive(int $limit): array
    {
        GameListing::stale()->delete();

        return GameListing::active()
            ->inRandomOrder()
            ->limit($limit)
            ->pluck('game_id')
            ->map(strval(...))
            ->values()
            ->all();
    }
}
