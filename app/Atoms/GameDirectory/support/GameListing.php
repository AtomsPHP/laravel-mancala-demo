<?php

declare(strict_types=1);

namespace App\Atoms\GameDirectory\Support;

use Illuminate\Database\Eloquent\Model;

/** One lobby row, as an Eloquent model over the directory's own SQLite table. */
class GameListing extends Model
{
    protected $table = 'games';

    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $keyType = 'string';

    // The Atom writes created_at/expires_at itself, so Eloquent shouldn't touch them.
    public $timestamps = false;

    protected $guarded = [];
}
