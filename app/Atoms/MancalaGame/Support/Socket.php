<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A seated WebSocket. Watchers are never recorded, so a missing row means
 * "may not move".
 *
 * @property string $connection_id
 * @property int $seat
 */
class Socket extends Model
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
