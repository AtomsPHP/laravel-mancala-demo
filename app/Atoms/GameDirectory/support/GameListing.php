<?php

declare(strict_types=1);

namespace App\Atoms\GameDirectory\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One lobby row, as an Eloquent model over the directory's own SQLite table.
 *
 * A support class: it ships with GameDirectory and runs Atom-side, where the
 * atoms/database-illuminate bridge must be booted before this model is asked
 * anything. Timestamps are DATE_ATOM strings written by the Atom, never
 * Eloquent's own clock, and instances stay inside the Atom; only plain
 * arrays cross the wire.
 */
final class GameListing extends Model
{
    protected $table = 'games';

    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
