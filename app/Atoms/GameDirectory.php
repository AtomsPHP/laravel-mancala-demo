<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;
use Illuminate\Database\Eloquent\Model;

/**
 * One lobby row, as an Eloquent model over the directory's own SQLite table.
 *
 * It lives in the Atom's file on purpose: that is what ships it in the
 * bundle — a model in a file of its own would be neither an Atom nor a pure
 * Shared DTO, and Shared code may not touch Illuminate at all. Timestamps
 * are DATE_ATOM strings written by the Atom, never Eloquent's own clock,
 * and instances stay inside the Atom; only plain arrays cross the wire.
 */
final class GameListing extends Model
{
    protected $table = 'games';

    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A tiny durable index; each actual game still owns its authoritative state.
 *
 * Rows are read and written through {@see GameListing} and the query builder,
 * both running against this Atom's own SQLite database via the
 * atoms/database-illuminate bridge — each method boots it first, since
 * Eloquent's resolver points wherever the last boot aimed it.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
final class GameDirectory extends Atom
{
    public const ID = 'public-mancala-lobby';

    public function register(
        string $gameId,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ): void {
        EloquentBridge::boot($this->db());

        $stamp = $createdAt->format(DATE_ATOM);
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
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        if (!in_array($status, ['waiting', 'active', 'finished', 'expired'], true)) {
            throw new \DomainException('invalid_game_status');
        }

        EloquentBridge::boot($this->db());

        $changes = ['status' => $status, 'updated_at' => $updatedAt->format(DATE_ATOM)];
        if ($expiresAt !== null) {
            $changes['expires_at'] = $expiresAt->format(DATE_ATOM);
        }

        GameListing::query()->whereKey($gameId)->update($changes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function randomActive(\DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = EloquentBridge::boot($this->db());
        $stamp = $now->format(DATE_ATOM);

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
