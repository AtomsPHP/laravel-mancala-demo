<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use Atoms\Laravel\Facades\Atoms;
use Tests\TestCase;

final class MancalaDemoTest extends TestCase
{
    public function testApplicationPagesRenderTheVueShell(): void
    {
        $this->withoutVite();

        $gameId = str_repeat('a', 32);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('window.__MANCALA__', false);

        $this->get('/games/' . $gameId . '?observe=1')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSee('\\u0022mode\\u0022:\\u0022observe\\u0022', false)
            ->assertSee($gameId, false);
    }

    public function testAVisitorCanCreateAGame(): void
    {
        $fake = Atoms::fake([
            MancalaGame::class => ['create' => ['status' => 'waiting', 'revision' => 0]],
            GameDirectory::class => ['register' => null],
        ]);

        $response = $this->postJson('/api/games', ['client_id' => 'browser-one'])
            ->assertCreated()
            ->assertJsonPath('state.status', 'waiting')
            ->assertJsonStructure(['id', 'url', 'expires_at', 'state']);

        $id = (string) $response->json('id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertStringEndsWith('/games/' . $id, (string) $response->json('url'));
        $fake->assertInvoked(
            MancalaGame::class,
            'create',
            static fn (string $clientId): bool => $clientId === 'browser-one',
        );
        $fake->assertInvoked(
            GameDirectory::class,
            'register',
            static fn (string $gameId): bool => $gameId === $id,
        );
    }

    public function testCreationRejectsAnInvalidBrowserIdentity(): void
    {
        Atoms::fake();

        $this->postJson('/api/games', ['client_id' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    }

    public function testLobbyReturnsVerifiedActiveGamesOnly(): void
    {
        $fake = Atoms::fake([
            GameDirectory::class => [
                'randomActive' => [
                    ['game_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
                    ['game_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
                ],
                'updateStatus' => null,
            ],
            MancalaGame::class => [
                'snapshot' => [
                    'status' => 'active',
                    'created_at' => '2099-01-01T00:00:00+00:00',
                    'expires_at' => '2099-01-02T00:00:00+00:00',
                    'stores' => [8, 9],
                    'turn' => 1,
                    'revision' => 12,
                ],
            ],
        ]);

        $this->getJson('/api/games/in-progress')
            ->assertOk()
            ->assertJsonCount(2, 'games')
            ->assertJsonPath('games.0.stores.0', 8)
            ->assertJsonPath('games.0.turn', 1);

        $fake->assertInvoked(GameDirectory::class, 'randomActive');
        $fake->assertInvoked(MancalaGame::class, 'snapshot');
    }
}
