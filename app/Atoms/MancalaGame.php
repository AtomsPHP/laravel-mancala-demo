<?php

declare(strict_types=1);

namespace App\Atoms;

use App\Atoms\Jobs\UpdateGameListing;
use App\Atoms\MancalaGame\Support\Game;
use App\Atoms\MancalaGame\Support\HandlesSocketFrames;
use App\Atoms\MancalaGame\Support\PlayerConnection;
use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;
use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/**
 * One Mancala table. The Game model owns the rules and the rows; this class
 * is the runtime surface: RPC, sockets, timers, and broadcasts. Turns are
 * serialized by Cloudflare, so no lock is needed here.
 *
 * @extends Atom<\Atoms\AtomMethods>
 */
class MancalaGame extends Atom
{
    use HandlesSocketFrames;

    private const EXPIRY_TIMER = 'expire-game';

    protected function onActivation(): void
    {
        EloquentBridge::boot($this->db());
    }

    /** @return array<string, mixed> */
    public function create(string $creatorId): array
    {
        if (Game::current() !== null) {
            throw new \DomainException('game_already_exists');
        }

        $expiresAt = new \DateTimeImmutable("+{$this->config('game_lifetime_hours')} hours");
        $game = Game::start($creatorId, $expiresAt);
        $this->timers()->schedule(self::EXPIRY_TIMER, $expiresAt);

        return $game->toState($this->id);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        Game::current()?->expireIfDue();

        return $this->state();
    }

    /**
     * @param Connection $conn
     * @param array<string, string> $params
     */
    public function onConnect($conn, array $params): void
    {
        $clientId = trim($params['client_id'] ?? '');
        $game = Game::current();

        if ($clientId === '' || $game === null) {
            $this->refuse($conn, 'game_not_found', 4404, 'Game not found');

            return;
        }

        if ($game->expireIfDue()) {
            $this->refuse($conn, 'game_expired', 4408, 'Game expired');

            return;
        }

        $joined = ($params['mode'] ?? 'player') === 'observe'
            ? ['seat' => null, 'started' => false]
            : $game->seat($conn->id(), $clientId);
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

        $seat = PlayerConnection::seatOf($conn->id());
        if ($seat === null) {
            $this->fail($conn, 'observer_cannot_move');

            return;
        }

        try {
            $game = Game::current() ?? throw new \DomainException('game_not_found');
            $move = $game->play($seat, $frame['pit'], $frame['revision']);
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
        PlayerConnection::query()->whereKey($conn->id())->delete();
    }

    protected function onTimer(string $name): void
    {
        if ($name !== self::EXPIRY_TIMER || !Game::current()?->expireIfDue(force: true)) {
            return;
        }

        $this->broadcast('game', ['kind' => 'expired']);
        $this->queueListingUpdate('expired', '');
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return Game::current()?->toState($this->id) ?? ['status' => 'missing'];
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
}
