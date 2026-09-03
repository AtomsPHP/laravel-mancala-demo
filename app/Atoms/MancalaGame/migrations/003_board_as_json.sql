-- The board is one value the game reads and writes whole, so it now lives on
-- the game row as JSON instead of twelve pit rows plus two store columns. The
-- copy below runs before the old columns go, so a game in play keeps its board.
ALTER TABLE game ADD COLUMN board TEXT NOT NULL DEFAULT '{"pits":[],"stores":[0,0]}';

UPDATE game SET board = json_object(
    'pits', (SELECT json_group_array(stones) FROM (SELECT stones FROM pits ORDER BY pit)),
    'stores', json_array(store_0, store_1)
);

DROP TABLE pits;
ALTER TABLE game DROP COLUMN store_0;
ALTER TABLE game DROP COLUMN store_1;
