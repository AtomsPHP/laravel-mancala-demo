<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\UpdateGameListing;
use Atoms\Atom;
use Atoms\Database;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One complete Mancala table: board, seats, sockets, turns, and lifetime.
 *
 * The browser sends only a pit and the revision it is looking at. Every move
 * is applied during one serialized Atom turn, then broadcast as an ordered
 * drop path so every connected board can animate the same action.
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

            $db->execute(
                'INSERT INTO game '
                . '(id, status, created_at, expires_at, turn, revision, store_0, store_1, winner) '
                . 'VALUES (1, ?, ?, ?, 0, 0, 0, 0, NULL)',
                ['waiting', $createdAt->format(DATE_ATOM), $expiresAt->format(DATE_ATOM)],
            );
            $db->execute('INSERT INTO players (seat, client_id) VALUES (0, ?)', [$creatorId]);

            for ($pit = 0; $pit < 12; $pit++) {
                $db->execute('INSERT INTO pits (pit, stones) VALUES (?, 4)', [$pit]);
            }
        });

        $this->timers()->schedule(self::EXPIRY_TIMER, $expiresAt);

        return $this->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->expireIfDue(new \DateTimeImmutable());

        return $this->readState();
    }

    /**
     * WebSocket lifecycle methods are runtime entrypoints, not RPC methods;
     * their interface types live in PHPDoc so boundary analysis does not
     * mistake them for serialized arguments.
     *
     * @param Connection $conn
     * @param array<string, string> $params
     */
    public function onConnect($conn, array $params): void
    {
        $clientId = trim($params['client_id'] ?? '');
        $observe = ($params['mode'] ?? 'player') === 'observe';

        if ($clientId === '' || !$this->hasGame()) {
            $this->sendError($conn, 'game_not_found');
            $conn->close(4404, 'Game not found');

            return;
        }

        if ($this->expireIfDue(new \DateTimeImmutable())) {
            $this->sendError($conn, 'game_expired');
            $conn->close(4408, 'Game expired');

            return;
        }

        $joined = $this->db()->transaction(function (Database $db) use ($conn, $clientId, $observe): array {
            $game = $this->gameRow($db);
            $seat = null;
            $started = false;

            if (!$observe) {
                $player = $db->query('SELECT seat FROM players WHERE client_id = ?', [$clientId]);
                if ($player !== []) {
                    $seat = (int) $player[0]['seat'];
                } elseif ($game['status'] === 'waiting') {
                    $seat = 1;
                    $db->execute('INSERT INTO players (seat, client_id) VALUES (1, ?)', [$clientId]);
                    $db->execute("UPDATE game SET status = 'active' WHERE id = 1");
                    $started = true;
                }
            }

            $db->execute(
                'INSERT INTO connections (connection_id, client_id, seat, mode) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT(connection_id) DO UPDATE SET client_id = excluded.client_id, '
                . 'seat = excluded.seat, mode = excluded.mode',
                [$conn->id(), $clientId, $seat, $seat === null ? 'observer' : 'player'],
            );

            return ['seat' => $seat, 'started' => $started];
        });

        $state = $this->readState();
        $conn->send($this->json([
            'kind' => 'welcome',
            'role' => $joined['seat'] === null ? 'observer' : 'player',
            'seat' => $joined['seat'],
            'state' => $state,
        ]));

        if ($joined['started'] === true) {
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
        if ($msg->isBinary()) {
            $this->sendError($conn, 'binary_not_supported');

            return;
        }

        try {
            $frame = json_decode($msg->payload(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->sendError($conn, 'invalid_message');

            return;
        }

        if (!is_array($frame) || ($frame['kind'] ?? null) !== 'move'
            || !is_int($frame['pit'] ?? null) || !is_int($frame['revision'] ?? null)) {
            $this->sendError($conn, 'invalid_message');

            return;
        }

        $connection = $this->db()->query(
            'SELECT seat FROM connections WHERE connection_id = ? AND mode = ?',
            [$conn->id(), 'player'],
        );
        if ($connection === [] || $connection[0]['seat'] === null) {
            $this->sendError($conn, 'observer_cannot_move');

            return;
        }

        try {
            $event = $this->move((int) $connection[0]['seat'], $frame['pit'], $frame['revision']);
        } catch (\DomainException $error) {
            $this->sendError($conn, $error->getMessage(), $this->readState());

            return;
        }

        $this->broadcast('game', $event);
        $state = $event['state'];
        if (is_array($state) && ($state['status'] ?? null) === 'finished') {
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
        if ($name !== self::EXPIRY_TIMER || !$this->expire()) {
            return;
        }

        $this->broadcast('game', ['kind' => 'expired']);
        $this->queueListingUpdate('expired', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function move(int $seat, int $sourcePit, int $expectedRevision): array
    {
        return $this->db()->transaction(function (Database $db) use ($seat, $sourcePit, $expectedRevision): array {
            $game = $this->gameRow($db);
            if ($game['status'] !== 'active') {
                throw new \DomainException('game_not_active');
            }
            if ((int) $game['revision'] !== $expectedRevision) {
                throw new \DomainException('stale_revision');
            }
            if ((int) $game['turn'] !== $seat) {
                throw new \DomainException('not_your_turn');
            }
            if (!$this->ownsPit($seat, $sourcePit)) {
                throw new \DomainException('pit_not_owned');
            }

            $pits = $this->readPits($db);
            $stones = $pits[$sourcePit] ?? 0;
            if ($stones === 0) {
                throw new \DomainException('pit_empty');
            }

            $stores = [(int) $game['store_0'], (int) $game['store_1']];
            $pits[$sourcePit] = 0;
            $position = $sourcePit < 6 ? $sourcePit : $sourcePit + 1;
            $path = [];
            $lastPit = null;
            $lastStore = null;

            while ($stones > 0) {
                $position = ($position + 1) % 14;
                if (($seat === 0 && $position === 13) || ($seat === 1 && $position === 6)) {
                    continue;
                }

                if ($position === 6 || $position === 13) {
                    $owner = $position === 6 ? 0 : 1;
                    $stores[$owner]++;
                    $lastStore = $owner;
                    $lastPit = null;
                    $path[] = ['kind' => 'store', 'player' => $owner];
                } else {
                    $pit = $position < 6 ? $position : $position - 1;
                    $pits[$pit]++;
                    $lastPit = $pit;
                    $lastStore = null;
                    $path[] = ['kind' => 'pit', 'index' => $pit];
                }

                $stones--;
            }

            $capture = null;
            if ($lastPit !== null && $this->ownsPit($seat, $lastPit) && $pits[$lastPit] === 1) {
                $opposite = 11 - $lastPit;
                if ($pits[$opposite] > 0) {
                    $captured = $pits[$opposite] + 1;
                    $pits[$lastPit] = 0;
                    $pits[$opposite] = 0;
                    $stores[$seat] += $captured;
                    $capture = ['pit' => $lastPit, 'opposite' => $opposite, 'stones' => $captured];
                }
            }

            $finished = $this->sideTotal($pits, 0) === 0 || $this->sideTotal($pits, 1) === 0;
            $extraTurn = !$finished && $lastStore === $seat;
            $winner = null;
            $turn = $extraTurn ? $seat : 1 - $seat;
            $status = 'active';

            if ($finished) {
                $stores[0] += $this->sweepSide($pits, 0);
                $stores[1] += $this->sweepSide($pits, 1);
                $status = 'finished';
                $turn = null;
                $winner = $stores[0] === $stores[1] ? -1 : ($stores[0] > $stores[1] ? 0 : 1);
            }

            foreach ($pits as $pit => $count) {
                $db->execute('UPDATE pits SET stones = ? WHERE pit = ?', [$count, $pit]);
            }
            $db->execute(
                'UPDATE game SET status = ?, turn = ?, revision = revision + 1, '
                . 'store_0 = ?, store_1 = ?, winner = ? WHERE id = 1',
                [$status, $turn, $stores[0], $stores[1], $winner],
            );

            return [
                'kind' => 'moved',
                'actor' => $seat,
                'source_pit' => $sourcePit,
                'path' => $path,
                'capture' => $capture,
                'extra_turn' => $extraTurn,
                'state' => $this->readState($db),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(?Database $database = null): array
    {
        $db = $database ?? $this->db();
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
        $pits = array_fill(0, 12, 0);
        foreach ($db->query('SELECT pit, stones FROM pits ORDER BY pit') as $row) {
            $pits[(int) $row['pit']] = (int) $row['stones'];
        }

        return $pits;
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

    /** @param array<int, int> $pits */
    private function sideTotal(array $pits, int $seat): int
    {
        return array_sum(array_slice($pits, $seat === 0 ? 0 : 6, 6));
    }

    /** @param array<int, int> $pits */
    private function sweepSide(array &$pits, int $seat): int
    {
        $start = $seat === 0 ? 0 : 6;
        $total = 0;
        for ($pit = $start; $pit < $start + 6; $pit++) {
            $total += $pits[$pit];
            $pits[$pit] = 0;
        }

        return $total;
    }

    private function ownsPit(int $seat, int $pit): bool
    {
        return $seat === 0 ? $pit >= 0 && $pit < 6 : $pit >= 6 && $pit < 12;
    }

    private function hasGame(): bool
    {
        return $this->db()->query('SELECT id FROM game WHERE id = 1') !== [];
    }

    private function expireIfDue(\DateTimeImmutable $now): bool
    {
        $rows = $this->db()->query('SELECT status, expires_at FROM game WHERE id = 1');
        if ($rows === [] || $rows[0]['status'] === 'expired'
            || new \DateTimeImmutable((string) $rows[0]['expires_at']) > $now) {
            return false;
        }

        return $this->expire();
    }

    private function expire(): bool
    {
        return $this->db()->transaction(function (Database $db): bool {
            $rows = $db->query('SELECT status FROM game WHERE id = 1');
            if ($rows === [] || $rows[0]['status'] === 'expired') {
                return false;
            }

            $db->execute('DELETE FROM connections');
            $db->execute('DELETE FROM players');
            $db->execute('DELETE FROM pits');
            $db->execute("UPDATE game SET status = 'expired', turn = NULL, store_0 = 0, store_1 = 0 WHERE id = 1");

            return true;
        });
    }

    /** @param array<string, mixed>|null $state */
    private function sendError(Connection $conn, string $code, ?array $state = null): void
    {
        $frame = ['kind' => 'error', 'code' => $code];
        if ($state !== null) {
            $frame['state'] = $state;
        }
        $conn->send($this->json($frame));
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

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
