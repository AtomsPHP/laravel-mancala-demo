<?php

declare(strict_types=1);

namespace App\Atoms\GameDirectory\Support;

use App\Atoms\Shared\GameStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One lobby row in the directory's own SQLite table.
 *
 * @property string $game_id
 * @property GameStatus $status
 * @property \Carbon\CarbonImmutable $expires_at
 */
class GameListing extends Model
{
    public $incrementing = false;

    protected $table = 'games';

    protected $primaryKey = 'game_id';

    protected $keyType = 'string';

    protected $dateFormat = DATE_ATOM;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => GameStatus::class, 'expires_at' => 'immutable_datetime'];
    }

    /** @return Builder<static> */
    public static function active(): Builder
    {
        return static::query()
            ->where('status', GameStatus::Active)
            ->where('expires_at', '>', date(DATE_ATOM));
    }

    /** @return Builder<static> */
    public static function stale(): Builder
    {
        return static::query()
            ->where('expires_at', '<=', date(DATE_ATOM))
            ->orWhere(static fn (Builder $query) => $query->whereIn('status', [GameStatus::Finished, GameStatus::Expired]));
    }
}
