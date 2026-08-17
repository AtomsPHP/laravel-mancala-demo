<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\MancalaGame;
use Atoms\Testing\AtomHarness;
use PHPUnit\Framework\TestCase;

/**
 * `connections` records seated sockets and nothing else. A watcher is refused a
 * move because no row names it — which is worth pinning, since the refusal used
 * to come from a row that said 'observer' and the two are indistinguishable
 * from the client's side.
 */
final class MancalaGameConnectionsTest extends TestCase
{
    public function testAWatcherIsNeverWrittenDown(): void
    {
        $game = $this->game();

        $conn = $game->connect(['client_id' => 'a-watcher', 'mode' => 'observe']);

        self::assertSame('observer', $conn->sentJson()[0]['role']);
        self::assertSame([], $game->db()->query('SELECT * FROM connections'));

        $game->sendMessage($conn, (string) json_encode(['kind' => 'move', 'pit' => 0, 'revision' => 0]));

        self::assertSame('observer_cannot_move', $conn->sentJson()[1]['code']);
    }

    public function testASeatedSocketIsRecordedAndReleasedOnDisconnect(): void
    {
        $game = $this->game();

        $conn = $game->connect(['client_id' => 'seat-key-one', 'mode' => 'player']);

        self::assertSame(0, $conn->sentJson()[0]['seat']);
        self::assertSame(
            [['connection_id' => $conn->id(), 'seat' => 0]],
            $game->db()->query('SELECT connection_id, seat FROM connections'),
        );

        $game->disconnect($conn);

        self::assertSame([], $game->db()->query('SELECT * FROM connections'));
    }

    public function testAPlayerWatchingTheirOwnGameIsSeatedNowhere(): void
    {
        $game = $this->game();

        // Seating is decided once, at the handshake. The seat key still owns
        // seat 0, but this socket asked to watch, so it may not move.
        $conn = $game->connect(['client_id' => 'seat-key-one', 'mode' => 'observe']);

        self::assertSame('observer', $conn->sentJson()[0]['role']);
        self::assertSame([], $game->db()->query('SELECT * FROM connections'));

        $game->sendMessage($conn, (string) json_encode(['kind' => 'move', 'pit' => 0, 'revision' => 0]));

        self::assertSame('observer_cannot_move', $conn->sentJson()[1]['code']);
    }

    public function testAThirdArrivalWantingToPlayWatchesInstead(): void
    {
        $game = $this->game();

        $game->connect(['client_id' => 'seat-key-two', 'mode' => 'player']);
        $third = $game->connect(['client_id' => 'seat-key-three', 'mode' => 'player']);

        self::assertSame('observer', $third->sentJson()[0]['role']);
        self::assertSame(
            [],
            $game->db()->query('SELECT connection_id FROM connections WHERE connection_id = ?', [$third->id()]),
        );
    }

    /**
     * A booted game with seat 0 taken by `seat-key-one` and seat 1 open.
     *
     * @return AtomHarness<MancalaGame>
     */
    private function game(): AtomHarness
    {
        $harness = AtomHarness::for(MancalaGame::class, str_repeat('a', 32));

        $harness->invoke('create', [
            'seat-key-one',
            new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2099-01-02T00:00:00+00:00'),
        ]);

        return $harness;
    }
}
