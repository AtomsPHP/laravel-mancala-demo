<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use App\Atoms\Shared\Board;
use App\Atoms\Shared\GameStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row describing this table. Every Atom owns its own SQLite
 * database, so there is exactly one Game per MancalaGame.
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

    public function isDue(): bool
    {
        return $this->expires_at->isPast();
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
