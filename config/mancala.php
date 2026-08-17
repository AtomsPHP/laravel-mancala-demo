<?php

declare(strict_types=1);

return [
    'game_lifetime_hours' => (int) env('MANCALA_GAME_LIFETIME_HOURS', 24),
    'discovery_limit' => (int) env('MANCALA_DISCOVERY_LIMIT', 5),
    'discovery_candidates' => (int) env('MANCALA_DISCOVERY_CANDIDATES', 20),
    'lobby_refresh_ms' => (int) env('MANCALA_LOBBY_REFRESH_MS', 15000),
    'stone_drop_ms' => (int) env('MANCALA_STONE_DROP_MS', 220),
    'reconnect_ms' => (int) env('MANCALA_RECONNECT_MS', 1200),

    // Each reconnect attempt costs two requests: the browser asks Laravel for a
    // ticket, and Laravel asks the Worker to mint it. Retrying at a flat
    // reconnect_ms would be 50 mints a minute and trip the limit below, so the
    // delay doubles up to this ceiling and resets once a socket opens.
    'reconnect_max_ms' => (int) env('MANCALA_RECONNECT_MAX_MS', 15000),

    // Per-session ceiling on ticket mints. Anyone who can open a game page can
    // make Laravel call the Worker, so this is the one route here that needs a
    // limit. Under the backoff above, a browser that never connects mints about
    // seven times a minute.
    'ticket_mints_per_minute' => (int) env('MANCALA_TICKET_MINTS_PER_MINUTE', 30),
];
