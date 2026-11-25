<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Who holds each seat, keyed by the seat key Laravel signs into the ticket.
 *
 * @property int $seat
 * @property string $client_id
 */
class Player extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'seat';

    protected $guarded = [];

    public static function seatOf(string $clientId): ?int
    {
        $seat = self::query()->where('client_id', $clientId)->value('seat');

        return $seat === null ? null : (int) $seat;
    }
}
