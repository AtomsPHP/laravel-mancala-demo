<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\UpdateGameListing;
use App\Atoms\MancalaGame\Support\Game;
use App\Atoms\MancalaGame\Support\Player;
use App\Atoms\MancalaGame\Support\Socket;
use App\Atoms\Shared\Board;
use App\Atoms\Shared\GameStatus;
use App\Atoms\Shared\Move;
use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One Mancala table: board, seats, sockets, turns, and lifetime.
 * Turns are serialized by Cloudflare, so no lock is needed here.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
class MancalaGame extends Atom
{
    private const EXPIRY_TIMER = 'expire-game';

    protected function onActivation(): void
    {
        EloquentBridge::boot($this->db());
    }

    /** @return array<string, mixed> */
    public function create(string $creatorId, \DateTimeImmutable $expiresAt): array
    {
        if (Game::current() !== null) {
            throw new \DomainException('game_already_exists');
        }

        $game = $this->transaction(function () use ($creatorId, $expiresAt): Game {
            Player::query()->create(['seat' => 0, 'client_id' => $creatorId]);

            return Game::query()->create(['board' => Board::opening(), 'expires_at' => $expiresAt]);
        });

        $this->timers()->schedule(self::EXPIRY_TIMER, $expiresAt);

        return $game->toState($this->id);
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

        if ($clientId === '' || Game::current() === null) {
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

        $seat = Socket::seatOf($conn->id());
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
        Socket::query()->whereKey($conn->id())->delete();
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
     * An established player keeps their seat, the first newcomer takes seat 1
     * and starts the game, and everyone else watches.
     *
     * @return array{seat: int|null, started: bool}
     */
    private function claimSeat(Connection $conn, string $clientId, bool $observe): array
    {
        if ($observe) {
            return ['seat' => null, 'started' => false];
        }

        return $this->transaction(function () use ($conn, $clientId): array {
            $game = Game::current() ?? throw new \DomainException('game_not_found');
            $seat = Player::seatOf($clientId);
            $started = false;

            if ($seat === null && $game->status === GameStatus::Waiting) {
                Player::query()->create(['seat' => $seat = 1, 'client_id' => $clientId]);
                $game->update(['status' => GameStatus::Active]);
                $started = true;
            }

            if ($seat !== null) {
                Socket::query()->create(['connection_id' => $conn->id(), 'seat' => $seat]);
            }

            return ['seat' => $seat, 'started' => $started];
        });
    }

    /** Validate and apply one move; the rules themselves live in Board. */
    private function play(int $seat, int $pit, int $expectedRevision): Move
    {
        $game = Game::current() ?? throw new \DomainException('game_not_found');

        match (true) {
            $game->status !== GameStatus::Active => throw new \DomainException('game_not_active'),
            $game->revision !== $expectedRevision => throw new \DomainException('stale_revision'),
            $game->turn !== $seat => throw new \DomainException('not_your_turn'),
            !Board::owns($seat, $pit) => throw new \DomainException('pit_not_owned'),
            $game->board->stones($pit) === 0 => throw new \DomainException('pit_empty'),
            default => null,
        };

        $move = $game->board->play($seat, $pit);

        $game->update([
            'board' => $move->board,
            'status' => $move->status(),
            'turn' => $move->nextTurn(),
            'winner' => $move->winner(),
            'revision' => $game->revision + 1,
        ]);

        return $move;
    }

    /**
     * Retire the table once its deadline passes, releasing seats and sockets.
     * The expiry timer forces this; every other caller checks the clock first.
     */
    private function expireIfDue(bool $force = false): bool
    {
        $game = Game::current();

        if ($game === null || $game->status === GameStatus::Expired || (!$force && !$game->isDue())) {
            return false;
        }

        $this->transaction(function () use ($game): void {
            Socket::query()->delete();
            Player::query()->delete();
            $game->update(['status' => GameStatus::Expired, 'turn' => null, 'board' => new Board([], [0, 0])]);
        });

        return true;
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
        return Game::current()?->toState($this->id) ?? ['status' => 'missing'];
    }

    /**
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    private function transaction(\Closure $callback): mixed
    {
        return Game::resolveConnection()->transaction($callback);
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
