CREATE TABLE game (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    status TEXT NOT NULL CHECK (status IN ('waiting', 'active', 'finished', 'expired')),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    turn INTEGER NULL CHECK (turn IN (0, 1) OR turn IS NULL),
    revision INTEGER NOT NULL DEFAULT 0,
    store_0 INTEGER NOT NULL DEFAULT 0,
    store_1 INTEGER NOT NULL DEFAULT 0,
    winner INTEGER NULL CHECK (winner IN (-1, 0, 1) OR winner IS NULL)
);

CREATE TABLE pits (
    pit INTEGER PRIMARY KEY CHECK (pit BETWEEN 0 AND 11),
    stones INTEGER NOT NULL CHECK (stones >= 0)
);

CREATE TABLE players (
    seat INTEGER PRIMARY KEY CHECK (seat IN (0, 1)),
    client_id TEXT NOT NULL UNIQUE
);

CREATE TABLE connections (
    connection_id TEXT PRIMARY KEY,
    client_id TEXT NOT NULL,
    seat INTEGER NULL CHECK (seat IN (0, 1) OR seat IS NULL),
    mode TEXT NOT NULL CHECK (mode IN ('player', 'observer'))
);
