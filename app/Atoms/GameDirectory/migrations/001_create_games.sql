CREATE TABLE games (
    game_id TEXT PRIMARY KEY,
    status TEXT NOT NULL CHECK (status IN ('waiting', 'active', 'finished', 'expired')),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX games_status_expires_at ON games (status, expires_at);
