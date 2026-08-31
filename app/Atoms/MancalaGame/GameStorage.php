<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame;

use App\Atoms\Shared\Board;
use App\Atoms\Shared\Move;
use Atoms\Attributes\SharedWithAtoms;
use Atoms\DatabaseIlluminate\AtomConnection;

/**
 * Every row MancalaGame reads, behind intention-revealing queries.
 * The Atom decides what a move means; this only knows where it is kept.
 *
 * Queries run through the atoms/database-illuminate bridge connection, the
 * Laravel query builder over this Atom's own SQLite database. The attribute
 * ships this class in the Atom bundle: only Atoms and shared classes cross
 * into the Worker, and a helper the build leaves behind would fail on first
 * use in production.
 */
#[SharedWithAtoms]
final class GameStorage
{
    public function __construct(private readonly AtomConnection $db)
    {
    }

    /** Seed the game row, the creator's seat, and the opening board. */
    public function create(string $creatorId, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): void
    {
        $this->db->table('game')->insert([
            'id' => 1,
            'status' => 'waiting',
            'created_at' => $createdAt->format(DATE_ATOM),
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'turn' => 0,
            'revision' => 0,
            'store_0' => 0,
            'store_1' => 0,
            'winner' => null,
        ]);

        $this->db->table('players')->insert(['seat' => 0, 'client_id' => $creatorId]);

        $this->writeBoard(Board::opening());
    }

    /** @return array<string, mixed>|null */
    public function game(): ?array
    {
        $row = $this->db->table('game')->where('id', 1)->first();

        return $row === null ? null : (array) $row;
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
            'players' => $this->db->table('players')->count(),
        ];
    }

    /** @return array<int, int> */
    public function pits(): array
    {
        $pits = array_fill(0, Board::PIT_COUNT, 0);
        foreach ($this->db->table('pits')->orderBy('pit')->get() as $row) {
            $pits[(int) $row->pit] = (int) $row->stones;
        }

        return $pits;
    }

    /** Upsert all twelve pits in one statement, creating them on first use. */
    public function writeBoard(Board $board): void
    {
        $rows = [];
        foreach ($board->pits as $pit => $stones) {
            $rows[] = ['pit' => $pit, 'stones' => $stones];
        }

        $this->db->table('pits')->upsert($rows, ['pit'], ['stones']);
    }

    /** Persist a resolved move: the board, and the game row's derived fields. */
    public function applyMove(Move $move): void
    {
        $this->writeBoard($move->board);
        $this->db->table('game')->where('id', 1)->update([
            'status' => $move->status(),
            'turn' => $move->nextTurn(),
            'revision' => $this->db->raw('revision + 1'),
            'store_0' => $move->board->stores[0],
            'store_1' => $move->board->stores[1],
            'winner' => $move->winner(),
        ]);
    }

    /** The seat this client already holds, if any. */
    public function getSeatForPlayer(string $clientId): ?int
    {
        $seat = $this->db->table('players')->where('client_id', $clientId)->value('seat');

        return $seat === null ? null : (int) $seat;
    }

    /**
     * The seat this connection may move for, or null if it is only watching.
     * A missing row is the ordinary case: watchers are never written down.
     */
    public function getSeatForConnection(string $connectionId): ?int
    {
        $seat = $this->db->table('connections')->where('connection_id', $connectionId)->value('seat');

        return $seat === null ? null : (int) $seat;
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
            $this->db->table('players')->insert(['seat' => 1, 'client_id' => $clientId]);
            $this->db->table('game')->where('id', 1)->update(['status' => 'active']);
        }

        if ($seat !== null) {
            $this->db->table('connections')->insert([
                'connection_id' => $connectionId,
                'seat' => $seat,
            ]);
        }

        return ['seat' => $seat, 'started' => $started];
    }

    /** Forget a seated socket; watchers were never written down to begin with. */
    public function releaseConnection(string $connectionId): void
    {
        $this->db->table('connections')->where('connection_id', $connectionId)->delete();
    }

    /** Wipe seats, sockets, and the board; the game row itself just flips to expired. */
    public function expire(): void
    {
        $this->db->table('connections')->delete();
        $this->db->table('players')->delete();
        $this->db->table('pits')->delete();
        $this->db->table('game')->where('id', 1)->update([
            'status' => 'expired',
            'turn' => null,
            'store_0' => 0,
            'store_1' => 0,
        ]);
    }
}
