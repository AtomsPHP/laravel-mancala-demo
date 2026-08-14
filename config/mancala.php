<?php

declare(strict_types=1);

return [
    'game_lifetime_hours' => (int) env('MANCALA_GAME_LIFETIME_HOURS', 24),
    'discovery_limit' => (int) env('MANCALA_DISCOVERY_LIMIT', 5),
    'discovery_candidates' => (int) env('MANCALA_DISCOVERY_CANDIDATES', 20),
    'lobby_refresh_ms' => (int) env('MANCALA_LOBBY_REFRESH_MS', 15000),
    'stone_drop_ms' => (int) env('MANCALA_STONE_DROP_MS', 220),
    'reconnect_ms' => (int) env('MANCALA_RECONNECT_MS', 1200),
    'client_id_max_bytes' => (int) env('MANCALA_CLIENT_ID_MAX_BYTES', 128),
];
