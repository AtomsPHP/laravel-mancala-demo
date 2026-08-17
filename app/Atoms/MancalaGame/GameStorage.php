<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame;

use App\Atoms\Shared\Board;
use App\Atoms\Shared\Move;
use Atoms\Attributes\SharedWithAtoms;
use Atoms\Database;

/**
 * Every row MancalaGame reads, behind intention-revealing queries.
 * The Atom decides what a move means; this only knows where it is kept.
 *
 * The attribute ships this class in the Atom bundle: only Atoms and shared
 * classes cross into the Worker, and a helper the build leaves behind would
 * fail on first use in production.
 */
#[SharedWithAtoms]
final class GameStorage
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Seed the game row, the creator's seat, and the opening board. */
    public function create(string $creatorId, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): void
    {
        $this->db->execute(<<<'SQL'
            INSERT INTO game (id, status, created_at, expires_at, turn, revision, store_0, store_1, winner)
            VALUES (1, 'waiting', ?, ?, 0, 0, 0, 0, NULL)
            SQL, [$createdAt->format(DATE_ATOM), $expiresAt->format(DATE_ATOM)]);

        $this->db->execute('INSERT INTO players (seat, client_id) VALUES (0, ?)', [$creatorId]);

        $this->writeBoard(Board::opening());
    }

    /** @return array<string, mixed>|null */
    public function game(): ?array
    {
        return $this->row('SELECT * FROM game WHERE id = 1');
    }

    /** @return array<string, mixed> */
    public function state(string $atomId): array
    {
        $game = $this->game();
        if ($game === null) {
            return ['status' => 'missing'];
        }

        $status = (string) $game['status'];

        return [
            'id' => $atomId,
            'status' => $status,
            'pits' => $status === 'expired' ? [] : $this->pits(),
            'stores' => [(int) $game['store_0'], (int) $game['store_1']],
            'turn' => $game['turn'] === null ? null : (int) $game['turn'],
            'revision' => (int) $game['revision'],
            'winner' => $game['winner'] === null ? null : (int) $game['winner'],
            'created_at' => (string) $game['created_at'],
            'expires_at' => (string) $game['expires_at'],
            'players' => (int) ($this->row('SELECT COUNT(*) AS total FROM players')['total'] ?? 0),
        ];
    }

    /** @return array<int, int> */
    public function pits(): array
    {
        $pits = array_fill(0, Board::PIT_COUNT, 0);
        foreach ($this->db->query('SELECT pit, stones FROM pits ORDER BY pit') as $row) {
            $pits[(int) $row['pit']] = (int) $row['stones'];
        }

        return $pits;
    }

    /** Upsert all twelve pits in one statement, creating them on first use. */
    public function writeBoard(Board $board): void
    {
        $bindings = [];
        foreach ($board->pits as $pit => $stones) {
            array_push($bindings, $pit, $stones);
        }

        $rows = implode(', ', array_fill(0, Board::PIT_COUNT, '(?, ?)'));
        $this->db->execute(
            "INSERT INTO pits (pit, stones) VALUES {$rows} "
            . 'ON CONFLICT(pit) DO UPDATE SET stones = excluded.stones',
            $bindings,
        );
    }

    /** Persist a resolved move: the board, and the game row's derived fields. */
    public function applyMove(Move $move): void
    {
        $this->writeBoard($move->board);
        $this->db->execute(<<<'SQL'
            UPDATE game
            SET status = ?, turn = ?, revision = revision + 1, store_0 = ?, store_1 = ?, winner = ?
            WHERE id = 1
            SQL, [
            $move->status(),
            $move->nextTurn(),
            $move->board->stores[0],
            $move->board->stores[1],
            $move->winner(),
        ]);
    }

    /** The seat this client already holds, if any. */
    public function getSeatForPlayer(string $clientId): ?int
    {
        $row = $this->row('SELECT seat FROM players WHERE client_id = ?', [$clientId]);

        return $row === null ? null : (int) $row['seat'];
    }

    /**
     * The seat this connection may move for, or null if it is only watching.
     * A missing row is the ordinary case: watchers are never written down.
     */
    public function getSeatForConnection(string $connectionId): ?int
    {
        $row = $this->row('SELECT seat FROM connections WHERE connection_id = ?', [$connectionId]);

        return $row === null ? null : (int) $row['seat'];
    }

    /**
     * An established player keeps their seat, the first newcomer takes seat 1
     * and starts the game, everyone else just gets their socket recorded
     * against a seat they already hold.
     *
     * @param array<string, mixed> $game
     * @return array{seat: int|null, started: bool}
     */
    public function claimSeat(string $connectionId, string $clientId, array $game): array
    {
        $seat = $this->getSeatForPlayer($clientId);
        $started = false;

        if ($seat === null && $game['status'] === 'waiting') {
            $seat = 1;
            $started = true;
            $this->db->execute('INSERT INTO players (seat, client_id) VALUES (1, ?)', [$clientId]);
            $this->db->execute("UPDATE game SET status = 'active' WHERE id = 1");
        }

        if ($seat !== null) {
            $this->db->execute(
                'INSERT INTO connections (connection_id, seat) VALUES (?, ?)',
                [$connectionId, $seat],
            );
        }

        return ['seat' => $seat, 'started' => $started];
    }

    /** Wipe seats, sockets, and the board; the game row itself just flips to expired. */
    public function expire(): void
    {
        $this->db->execute('DELETE FROM connections');
        $this->db->execute('DELETE FROM players');
        $this->db->execute('DELETE FROM pits');
        $this->db->execute("UPDATE game SET status = 'expired', turn = NULL, store_0 = 0, store_1 = 0 WHERE id = 1");
    }

    /**
     * @param array<int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    private function row(string $sql, array $bindings = []): ?array
    {
        return $this->db->query($sql, $bindings)[0] ?? null;
    }
}
