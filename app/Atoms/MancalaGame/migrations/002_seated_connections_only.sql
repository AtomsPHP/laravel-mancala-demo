-- `connections` answers exactly one question, asked once per move: which seat
-- may this socket play for? It held `client_id` and `mode` as well, neither of
-- which was ever read back, and it took a row for every observer -- rows whose
-- presence and absence produced the same answer. Only seated sockets are
-- recorded now, so a watcher costs no writes at all.
--
-- Dropping is safe: rows are keyed by a connection id the Worker mints per
-- accept, so nothing here outlives the sockets it describes.
DROP TABLE connections;

CREATE TABLE connections (
    connection_id TEXT PRIMARY KEY,
    seat INTEGER NOT NULL CHECK (seat IN (0, 1))
);
