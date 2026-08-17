<?php

declare(strict_types=1);

namespace App\Atoms;

use Atoms\Atom;

/**
 * A tiny durable index; each actual game still owns its authoritative state.
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
        $sql = <<<'SQL'
            INSERT INTO games (game_id, status, created_at, expires_at, updated_at)
            VALUES (?, 'waiting', ?, ?, ?)
            ON CONFLICT(game_id) DO UPDATE
            SET status = excluded.status, expires_at = excluded.expires_at, updated_at = excluded.updated_at
            SQL;

        $stamp = $createdAt->format(DATE_ATOM);
        $this->db()->execute($sql, [$gameId, $stamp, $expiresAt->format(DATE_ATOM), $stamp]);
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

        $this->db()->execute(<<<'SQL'
            UPDATE games
            SET status = ?, updated_at = ?, expires_at = COALESCE(?, expires_at)
            WHERE game_id = ?
            SQL, [$status, $updatedAt->format(DATE_ATOM), $expiresAt?->format(DATE_ATOM), $gameId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function randomActive(\DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $stamp = $now->format(DATE_ATOM);
        $this->db()->execute(
            "DELETE FROM games WHERE expires_at <= ? OR status IN ('finished', 'expired')",
            [$stamp],
        );

        return $this->db()->query(<<<'SQL'
            SELECT game_id, created_at, expires_at FROM games
            WHERE status = 'active' AND expires_at > ?
            ORDER BY RANDOM() LIMIT ?
            SQL, [$stamp, $limit]);
    }
}
