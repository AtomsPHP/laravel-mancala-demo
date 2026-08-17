<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\UpdateGameListing;
use App\Atoms\Shared\Board;
use App\Atoms\Shared\Move;
use Atoms\Atom;
use Atoms\Database;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One complete Mancala table: board, seats, sockets, turns, and lifetime.
 *
 * The browser sends only a pit and the revision it is looking at. Every move is
 * applied during one serialized Atom turn, then broadcast as an ordered drop
 * path so every connected board animates the same action.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
final class MancalaGame extends Atom
{
    private const EXPIRY_TIMER = 'expire-game';

    /**
     * @return array<string, mixed>
     */
    public function create(
        string $creatorId,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ): array {
        $this->db()->transaction(function (Database $db) use ($creatorId, $createdAt, $expiresAt): void {
            if ($db->query('SELECT id FROM game WHERE id = 1') !== []) {
                throw new \DomainException('game_already_exists');
            }

            $db->execute(<<<'SQL'
                INSERT INTO game (id, status, created_at, expires_at, turn, revision, store_0, store_1, winner)
                VALUES (1, 'waiting', ?, ?, 0, 0, 0, 0, NULL)
                SQL, [$createdAt->format(DATE_ATOM), $expiresAt->format(DATE_ATOM)]);

            $db->execute('INSERT INTO players (seat, client_id) VALUES (0, ?)', [$creatorId]);

            $this->writeBoard($db, Board::opening());
        });

        $this->timers()->schedule(self::EXPIRY_TIMER, $expiresAt);

        return $this->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->expireIfDue();

        return $this->readState();
    }

    /**
     * WebSocket hooks are runtime entrypoints, not RPC methods; their interface
     * types live in PHPDoc so boundary analysis does not mistake them for
     * serialized arguments.
     *
     * @param Connection $conn
     * @param array<string, string> $params
     */
    public function onConnect($conn, array $params): void
    {
        $clientId = trim($params['client_id'] ?? '');

        if ($clientId === '' || !$this->hasGame()) {
            $this->fail($conn, 'game_not_found');
            $conn->close(4404, 'Game not found');

            return;
        }

        if ($this->expireIfDue()) {
            $this->fail($conn, 'game_expired');
            $conn->close(4408, 'Game expired');

            return;
        }

        $joined = $this->claimSeat($conn, $clientId, ($params['mode'] ?? 'player') === 'observe');
        $state = $this->readState();

        $conn->sendJson([
            'kind' => 'welcome',
            'role' => $joined['seat'] === null ? 'observer' : 'player',
            'seat' => $joined['seat'],
            'state' => $state,
        ]);

        if ($joined['started']) {
            $this->broadcast('game', ['kind' => 'started', 'state' => $state]);
            $this->queueListingUpdate('active', (string) $state['expires_at']);
        }
    }

    /**
     * @param Connection $conn
     * @param Message $msg
     */
    public function onMessage($conn, $msg): void
    {
        $frame = $this->parseMove($msg);
        if ($frame === null) {
            $this->fail($conn, 'invalid_message');

            return;
        }

        $seat = $this->seatOf($conn);
        if ($seat === null) {
            $this->fail($conn, 'observer_cannot_move');

            return;
        }

        try {
            $move = $this->play($seat, $frame['pit'], $frame['revision']);
        } catch (\DomainException $error) {
            $this->fail($conn, $error->getMessage(), $this->readState());

            return;
        }

        $state = $this->readState();
        $this->broadcast('game', [
            'kind' => 'moved',
            'actor' => $move->actor,
            'source_pit' => $move->sourcePit,
            'path' => $move->path,
            'capture' => $move->capture,
            'extra_turn' => $move->extraTurn,
            'state' => $state,
        ]);

        if ($move->finished) {
            $this->queueListingUpdate('finished', (string) $state['expires_at']);
        }
    }

    /** @param Connection $conn */
    public function onDisconnect($conn): void
    {
        $this->db()->execute('DELETE FROM connections WHERE connection_id = ?', [$conn->id()]);
    }

    protected function onTimer(string $name): void
    {
        if ($name !== self::EXPIRY_TIMER || !$this->expireIfDue(force: true)) {
            return;
        }

        $this->broadcast('game', ['kind' => 'expired']);
        $this->queueListingUpdate('expired', '');
    }

    /**
     * Validate and apply one move inside a single transaction. The board rules
     * themselves live in Board; this decides only what is allowed and what is
     * written.
     */
    private function play(int $seat, int $sourcePit, int $expectedRevision): Move
    {
        return $this->db()->transaction(function (Database $db) use ($seat, $sourcePit, $expectedRevision): Move {
            $game = $this->gameRow($db);
            $board = new Board(
                $this->readPits($db),
                [(int) $game['store_0'], (int) $game['store_1']],
            );

            match (true) {
                $game['status'] !== 'active' => throw new \DomainException('game_not_active'),
                (int) $game['revision'] !== $expectedRevision => throw new \DomainException('stale_revision'),
                (int) $game['turn'] !== $seat => throw new \DomainException('not_your_turn'),
                !Board::owns($seat, $sourcePit) => throw new \DomainException('pit_not_owned'),
                $board->stones($sourcePit) === 0 => throw new \DomainException('pit_empty'),
                default => null,
            };

            $move = $board->play($seat, $sourcePit);

            $sql = <<<'SQL'
                UPDATE game
                SET status = ?, turn = ?, revision = revision + 1, store_0 = ?, store_1 = ?, winner = ?
                WHERE id = 1
                SQL;

            $this->writeBoard($db, $move->board);
            $db->execute($sql, [
                $move->status(),
                $move->nextTurn(),
                $move->board->stores[0],
                $move->board->stores[1],
                $move->winner(),
            ]);

            return $move;
        });
    }

    /**
     * Seat the connection: an established player keeps their seat, the first
     * newcomer takes seat 1 and starts the game, everyone else observes.
     *
     * Only a seated socket is recorded, because only a seated socket is ever
     * asked about again. A watcher writes nothing and takes no turn in the
     * database at all; onConnect has already established the game exists and
     * has not expired, so there is nothing left for it to read either.
     *
     * @param Connection $conn
     * @return array{seat: int|null, started: bool}
     */
    private function claimSeat($conn, string $clientId, bool $observe): array
    {
        if ($observe) {
            return ['seat' => null, 'started' => false];
        }

        return $this->db()->transaction(function (Database $db) use ($conn, $clientId): array {
            $game = $this->gameRow($db);
            $seat = null;
            $started = false;

            $player = $db->query('SELECT seat FROM players WHERE client_id = ?', [$clientId]);

            if ($player !== []) {
                $seat = (int) $player[0]['seat'];
            } elseif ($game['status'] === 'waiting') {
                $seat = 1;
                $started = true;
                $db->execute('INSERT INTO players (seat, client_id) VALUES (1, ?)', [$clientId]);
                $db->execute("UPDATE game SET status = 'active' WHERE id = 1");
            }

            // A third arrival to a table already seated twice falls through to
            // null and watches, unrecorded, like anyone else who cannot move.
            if ($seat !== null) {
                $db->execute(
                    'INSERT INTO connections (connection_id, seat) VALUES (?, ?)',
                    [$conn->id(), $seat],
                );
            }

            return ['seat' => $seat, 'started' => $started];
        });
    }

    /**
     * The seat this connection may move for, or null if it is only watching.
     * A missing row is the ordinary case: watchers are never written down.
     */
    private function seatOf(Connection $conn): ?int
    {
        $rows = $this->db()->query('SELECT seat FROM connections WHERE connection_id = ?', [$conn->id()]);

        return $rows === [] ? null : (int) $rows[0]['seat'];
    }

    /** @return array{pit: int, revision: int}|null */
    private function parseMove(Message $msg): ?array
    {
        try {
            $frame = $msg->json();
        } catch (\JsonException) {
            return null;
        }

        if (($frame['kind'] ?? null) !== 'move') {
            return null;
        }

        $pit = $frame['pit'] ?? null;
        $revision = $frame['revision'] ?? null;

        return is_int($pit) && is_int($revision) ? ['pit' => $pit, 'revision' => $revision] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $db = $this->db();
        $rows = $db->query('SELECT * FROM game WHERE id = 1');
        if ($rows === []) {
            return ['status' => 'missing'];
        }

        $game = $rows[0];
        $status = (string) $game['status'];

        return [
            'id' => $this->id,
            'status' => $status,
            'pits' => $status === 'expired' ? [] : $this->readPits($db),
            'stores' => [(int) $game['store_0'], (int) $game['store_1']],
            'turn' => $game['turn'] === null ? null : (int) $game['turn'],
            'revision' => (int) $game['revision'],
            'winner' => $game['winner'] === null ? null : (int) $game['winner'],
            'created_at' => (string) $game['created_at'],
            'expires_at' => (string) $game['expires_at'],
            'players' => (int) ($db->query('SELECT COUNT(*) AS total FROM players')[0]['total'] ?? 0),
        ];
    }

    /** @return array<int, int> */
    private function readPits(Database $db): array
    {
        $pits = array_fill(0, Board::PIT_COUNT, 0);
        foreach ($db->query('SELECT pit, stones FROM pits ORDER BY pit') as $row) {
            $pits[(int) $row['pit']] = (int) $row['stones'];
        }

        return $pits;
    }

    /** Write all twelve pits in one statement, creating them on first use. */
    private function writeBoard(Database $db, Board $board): void
    {
        $bindings = [];
        foreach ($board->pits as $pit => $stones) {
            $bindings[] = $pit;
            $bindings[] = $stones;
        }

        $rows = implode(', ', array_fill(0, Board::PIT_COUNT, '(?, ?)'));
        $db->execute(
            "INSERT INTO pits (pit, stones) VALUES {$rows} "
            . 'ON CONFLICT(pit) DO UPDATE SET stones = excluded.stones',
            $bindings,
        );
    }

    /** @return array<string, mixed> */
    private function gameRow(Database $db): array
    {
        $rows = $db->query('SELECT * FROM game WHERE id = 1');
        if ($rows === []) {
            throw new \DomainException('game_not_found');
        }

        return $rows[0];
    }

    private function hasGame(): bool
    {
        return $this->db()->query('SELECT id FROM game WHERE id = 1') !== [];
    }

    /**
     * Retire the table once its deadline passes, releasing seats and sockets.
     * The expiry timer forces this; every other caller checks the clock first.
     */
    private function expireIfDue(bool $force = false): bool
    {
        return $this->db()->transaction(function (Database $db) use ($force): bool {
            $rows = $db->query('SELECT status, expires_at FROM game WHERE id = 1');

            if ($rows === [] || $rows[0]['status'] === 'expired') {
                return false;
            }

            if (!$force && new \DateTimeImmutable((string) $rows[0]['expires_at']) > new \DateTimeImmutable()) {
                return false;
            }

            $db->execute('DELETE FROM connections');
            $db->execute('DELETE FROM players');
            $db->execute('DELETE FROM pits');
            $db->execute("UPDATE game SET status = 'expired', turn = NULL, store_0 = 0, store_1 = 0 WHERE id = 1");

            return true;
        });
    }

    private function queueListingUpdate(string $status, string $expiresAt): void
    {
        try {
            $this->dispatch(UpdateGameListing::class, [
                'gameId' => $this->id,
                'status' => $status,
                'expiresAt' => $expiresAt,
            ]);
        } catch (\Throwable) {
            // Discovery is best effort; GameDirectory is repaired by verified lobby reads.
        }
    }

    /** @param array<string, mixed>|null $state */
    private function fail(Connection $conn, string $code, ?array $state = null): void
    {
        $frame = ['kind' => 'error', 'code' => $code];
        if ($state !== null) {
            $frame['state'] = $state;
        }

        $conn->sendJson($frame);
    }
}
