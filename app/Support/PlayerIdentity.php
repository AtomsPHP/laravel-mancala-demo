<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The player's durable identity, owned by the server.
 *
 * This value is the seat key: `MancalaGame` stores it in `players.client_id`
 * and reclaims a seat by matching it. It therefore must not be something the
 * browser can choose, so it never leaves Laravel — it is signed into the
 * WebSocket connection ticket as a claim, and the Worker merges that claim
 * over the browser's own query parameters before `onConnect()` reads it.
 *
 * A browser profile is a session is a player. Two tabs in one profile share a
 * session, and so share a seat; two profiles are two players.
 */
final class PlayerIdentity
{
    public const SESSION_KEY = 'mancala_player_id';

    public static function for(Request $request): string
    {
        $session = $request->session();
        $existing = $session->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        $session->put(self::SESSION_KEY, $id);

        return $id;
    }
}
