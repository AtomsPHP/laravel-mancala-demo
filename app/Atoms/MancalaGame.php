<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\UpdateGameListing;
use App\Atoms\MancalaGame\GameStorage;
use App\Atoms\Shared\Board;
use App\Atoms\Shared\Move;
use Atoms\Atom;
use Atoms\Database;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One complete Mancala table: board, seats, sockets, turns, and lifetime.
 *
 * The browser sends only a pit and the revision it is looking at. Turns are
 * serialized by Cloudflare, so a double-submitted move can't race itself — no
 * lock needed here. Each move is broadcast as an ordered drop path so every
 * connected board animates the same action. The rules live in Board, the rows
 * in GameStorage; this class decides what is allowed to happen.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
final class MancalaGame extends Atom
{
    private const EXPIRY_TIMER = 'expire-game';

    /** @return array<string, mixed> */
    public function create(
        string $creatorId,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ): array {
        $this->db()->transaction(function (Database $db) use ($creatorId, $createdAt, $expiresAt): void {
            $storage = $this->storage($db);

            if ($storage->game() !== null) {
                throw new \DomainException('game_already_exists');
            }

            $storage->create($creatorId, $createdAt, $expiresAt);
        });

        $this->timers()->schedule(self::EXPIRY_TIMER, $expiresAt);

        return $this->snapshot();
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->expireIfDue();

        return $this->state();
    }

    /**
     * @param Connection $conn
     * @param array<string, string> $params
     */
    public function onConnect($conn, array $params): void
    {
        $clientId = trim($params['client_id'] ?? '');

        if ($clientId === '' || $this->storage()->game() === null) {
            $this->refuse($conn, 'game_not_found', 4404, 'Game not found');

            return;
        }

        if ($this->expireIfDue()) {
            $this->refuse($conn, 'game_expired', 4408, 'Game expired');

            return;
        }

        $joined = $this->claimSeat($conn, $clientId, ($params['mode'] ?? 'player') === 'observe');
        $state = $this->state();

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

        $seat = $this->storage()->getSeatForConnection($conn->id());
        if ($seat === null) {
            $this->fail($conn, 'observer_cannot_move');

            return;
        }

        try {
            $move = $this->play($seat, $frame['pit'], $frame['revision']);
        } catch (\DomainException $error) {
            $this->fail($conn, $error->getMessage(), $this->state());

            return;
        }

        $state = $this->state();
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
     * Observers never touch the database; everyone else is handed off to
     * GameStorage, which decides whether they're an established player, the
     * newcomer who starts the game, or just another seat holder.
     *
     * @return array{seat: int|null, started: bool}
     */
    private function claimSeat(Connection $conn, string $clientId, bool $observe): array
    {
        if ($observe) {
            return ['seat' => null, 'started' => false];
        }

        return $this->db()->transaction(function (Database $db) use ($conn, $clientId): array {
            $storage = $this->storage($db);
            $game = $storage->game() ?? throw new \DomainException('game_not_found');

            return $storage->claimSeat($conn->id(), $clientId, $game);
        });
    }

    /**
     * Validate and apply one move inside a single transaction; the rules
     * themselves live in Board.
     */
    private function play(int $seat, int $sourcePit, int $expectedRevision): Move
    {
        return $this->db()->transaction(function (Database $db) use ($seat, $sourcePit, $expectedRevision): Move {
            $storage = $this->storage($db);
            $game = $storage->game() ?? throw new \DomainException('game_not_found');
            $board = new Board(
                $storage->pits(),
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
            $storage->applyMove($move);

            return $move;
        });
    }

    /** @return array{pit: int, revision: int}|null */
    private function parseMove(Message $msg): ?array
    {
        try {
            $frame = $msg->json();
        } catch (\JsonException) {
            return null;
        }

        if (($frame['kind'] ?? null) !== 'move' || !is_int($frame['pit'] ?? null) || !is_int($frame['revision'] ?? null)) {
            return null;
        }

        return ['pit' => $frame['pit'], 'revision' => $frame['revision']];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return $this->storage()->state($this->id);
    }

    private function storage(?Database $db = null): GameStorage
    {
        return new GameStorage($db ?? $this->db());
    }

    /**
     * Retire the table once its deadline passes, releasing seats and sockets.
     * The expiry timer forces this; every other caller checks the clock first.
     */
    private function expireIfDue(bool $force = false): bool
    {
        return $this->db()->transaction(function (Database $db) use ($force): bool {
            $storage = $this->storage($db);
            $game = $storage->game();

            if ($game === null || $game['status'] === 'expired') {
                return false;
            }

            if (!$force && new \DateTimeImmutable((string) $game['expires_at']) > new \DateTimeImmutable()) {
                return false;
            }

            $storage->expire();

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

    private function refuse(Connection $conn, string $code, int $closeCode, string $reason): void
    {
        $this->fail($conn, $code);
        $conn->close($closeCode, $reason);
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
