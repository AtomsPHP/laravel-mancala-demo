<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\MancalaGame;
use Atoms\Testing\AtomHarness;
use PHPUnit\Framework\TestCase;

final class MancalaGameMovesTest extends TestCase
{
    public function testAMoveSowsTheBoardAndBumpsTheRevision(): void
    {
        $game = AtomHarness::for(MancalaGame::class, str_repeat('a', 32));
        $game->invoke('create', ['seat-key-one', new \DateTimeImmutable('2099-01-02T00:00:00+00:00')]);

        $one = $game->connect(['client_id' => 'seat-key-one', 'mode' => 'player']);
        $two = $game->connect(['client_id' => 'seat-key-two', 'mode' => 'player']);

        // Seat 0 sows pit 2: four stones land in pits 3, 4, 5 and the store.
        $game->sendMessage($one, (string) json_encode(['kind' => 'move', 'pit' => 2, 'revision' => 0]));

        $state = $game->invoke('snapshot');
        self::assertSame('active', $state['status']);
        self::assertSame([4, 4, 0, 5, 5, 5, 4, 4, 4, 4, 4, 4], $state['pits']);
        self::assertSame([1, 0], $state['stores']);
        self::assertSame(1, $state['revision']);
        self::assertSame(0, $state['turn'], 'landing in your own store buys another turn');
        self::assertSame(2, $state['players']);

        $game->sendMessage($two, (string) json_encode(['kind' => 'move', 'pit' => 6, 'revision' => 1]));

        self::assertSame('not_your_turn', $two->sentJson()[1]['code']);
    }

    public function testAnExpiredGameClearsTheBoard(): void
    {
        $game = AtomHarness::for(MancalaGame::class, str_repeat('b', 32));
        $game->invoke('create', ['seat-key-one', new \DateTimeImmutable('2000-01-01T00:00:00+00:00')]);

        $state = $game->invoke('snapshot');

        self::assertSame('expired', $state['status']);
        self::assertSame([], $state['pits']);
        self::assertSame([0, 0], $state['stores']);
        self::assertSame(0, $state['players']);
    }
}
