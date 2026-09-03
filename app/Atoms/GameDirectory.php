<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\GameDirectory\Support\GameListing;
use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;

/**
 * A tiny durable index; each actual game still owns its authoritative state.
 * Rows are read and written through {@see GameListing} over the
 * atoms/database-illuminate bridge, which each method boots first.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
class GameDirectory extends Atom
{
    public const ID = 'public-mancala-lobby';

    public function register(
        string $gameId,
        \DateTimeImmutable $expiresAt,
    ): void {
        EloquentBridge::boot($this->db());

        $stamp = (new \DateTimeImmutable())->format(DATE_ATOM);
        GameListing::query()->upsert(
            [[
                'game_id' => $gameId,
                'status' => 'waiting',
                'created_at' => $stamp,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'updated_at' => $stamp,
            ]],
            ['game_id'],
            ['status', 'expires_at', 'updated_at'],
        );
    }

    public function updateStatus(
        string $gameId,
        string $status,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        if (!in_array($status, ['waiting', 'active', 'finished', 'expired'], true)) {
            throw new \DomainException('invalid_game_status');
        }

        EloquentBridge::boot($this->db());

        $changes = ['status' => $status, 'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        if ($expiresAt !== null) {
            $changes['expires_at'] = $expiresAt->format(DATE_ATOM);
        }

        GameListing::query()->whereKey($gameId)->update($changes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function randomActive(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = EloquentBridge::boot($this->db());
        $stamp = (new \DateTimeImmutable())->format(DATE_ATOM);

        GameListing::query()
            ->where('expires_at', '<=', $stamp)
            ->orWhereIn('status', ['finished', 'expired'])
            ->delete();

        // The query builder rather than the model: RANDOM() and LIMIT belong
        // in the SQL, and these rows leave as plain arrays over the wire.
        $rows = $connection->table('games')
            ->where('status', 'active')
            ->where('expires_at', '>', $stamp)
            ->inRandomOrder()
            ->limit($limit)
            ->get(['game_id', 'created_at', 'expires_at']);

        $listings = [];
        foreach ($rows as $row) {
            $listings[] = (array) $row;
        }

        return $listings;
    }
}
