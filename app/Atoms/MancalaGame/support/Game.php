<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use App\Atoms\Shared\Board;
use App\Atoms\Shared\GameStatus;
use App\Atoms\Shared\Move;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row describing this table, and every way it can change. Each
 * Atom owns its own SQLite database, so there is exactly one Game per
 * MancalaGame.
 *
 * @property int $id
 * @property GameStatus $status
 * @property Board $board
 * @property int|null $turn
 * @property int $revision
 * @property int|null $winner
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $expires_at
 */
class Game extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'game';

    protected $guarded = [];

    protected $dateFormat = DATE_ATOM;

    protected $attributes = ['id' => 1, 'status' => 'waiting', 'turn' => 0, 'revision' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'turn' => 'integer',
            'revision' => 'integer',
            'winner' => 'integer',
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** The board is stored whole, as JSON. */
    protected function board(): Attribute
    {
        return Attribute::make(
            get: static fn (string $json): Board => Board::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR)),
            set: static fn (Board $board): string => json_encode($board->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    /** Seed the row, the creator's seat, and the opening board. */
    public static function start(string $creatorId, \DateTimeImmutable $expiresAt): self
    {
        return self::resolveConnection()->transaction(static function () use ($creatorId, $expiresAt): self {
            Player::query()->create(['seat' => 0, 'client_id' => $creatorId]);

            return self::query()->create(['board' => Board::opening(), 'expires_at' => $expiresAt]);
        });
    }

    /**
     * An established player keeps their seat, the first newcomer takes seat 1
     * and starts the game, and everyone else watches.
     *
     * @return array{seat: int|null, started: bool}
     */
    public function seat(string $connectionId, string $clientId): array
    {
        return $this->getConnection()->transaction(function () use ($connectionId, $clientId): array {
            $seat = Player::seatOf($clientId);
            $started = false;

            if ($seat === null && $this->status === GameStatus::Waiting) {
                Player::query()->create(['seat' => $seat = 1, 'client_id' => $clientId]);
                $this->update(['status' => GameStatus::Active]);
                $started = true;
            }

            if ($seat !== null) {
                PlayerConnection::query()->create(['connection_id' => $connectionId, 'seat' => $seat]);
            }

            return ['seat' => $seat, 'started' => $started];
        });
    }

    /** Validate and apply one move; the rules themselves live in Board. */
    public function play(int $seat, int $pit, int $expectedRevision): Move
    {
        match (true) {
            $this->status !== GameStatus::Active => throw new \DomainException('game_not_active'),
            $this->revision !== $expectedRevision => throw new \DomainException('stale_revision'),
            $this->turn !== $seat => throw new \DomainException('not_your_turn'),
            !Board::owns($seat, $pit) => throw new \DomainException('pit_not_owned'),
            $this->board->stones($pit) === 0 => throw new \DomainException('pit_empty'),
            default => null,
        };

        $move = $this->board->play($seat, $pit);

        $this->update([
            'board' => $move->board,
            'status' => $move->status(),
            'turn' => $move->nextTurn(),
            'winner' => $move->winner(),
            'revision' => $this->revision + 1,
        ]);

        return $move;
    }

    /**
     * Retire the table once its deadline passes, releasing seats and sockets.
     * The expiry timer forces this; every other caller checks the clock first.
     */
    public function expireIfDue(bool $force = false): bool
    {
        if ($this->status === GameStatus::Expired || (!$force && $this->expires_at->isFuture())) {
            return false;
        }

        $this->getConnection()->transaction(function (): void {
            PlayerConnection::query()->delete();
            Player::query()->delete();
            $this->update(['status' => GameStatus::Expired, 'turn' => null, 'board' => new Board([], [0, 0])]);
        });

        return true;
    }

    /** @return array<string, mixed> */
    public function toState(string $atomId): array
    {
        return [
            'id' => $atomId,
            'status' => $this->status->value,
            'pits' => $this->board->pits,
            'stores' => $this->board->stores,
            'turn' => $this->turn,
            'revision' => $this->revision,
            'winner' => $this->winner,
            'created_at' => $this->created_at->format(DATE_ATOM),
            'expires_at' => $this->expires_at->format(DATE_ATOM),
            'players' => Player::query()->count(),
        ];
    }
}
