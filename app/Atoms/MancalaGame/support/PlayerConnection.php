<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A WebSocket that may move for a seat. Watchers are never recorded, so a
 * missing row means "may not move".
 *
 * @property string $connection_id
 * @property int $seat
 */
class PlayerConnection extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'connections';

    protected $primaryKey = 'connection_id';

    protected $keyType = 'string';

    protected $guarded = [];

    public static function seatOf(string $connectionId): ?int
    {
        $seat = self::query()->whereKey($connectionId)->value('seat');

        return $seat === null ? null : (int) $seat;
    }
}
