<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use App\Support\PlayerIdentity;
use Atoms\Client\AtomsClient;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Tickets\TicketIssuer;
use Atoms\Laravel\AtomsManager;
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

        $response = $this->postJson('/api/games')
            ->assertCreated()
            ->assertJsonPath('state.status', 'waiting')
            ->assertJsonStructure(['id', 'url', 'expires_at', 'state']);

        $id = (string) $response->json('id');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertStringEndsWith('/games/' . $id, (string) $response->json('url'));
        $fake->assertInvoked(
            MancalaGame::class,
            'create',
            // The creator's seat is the session's player id, which the request
            // never carried — so any well-formed value here came from Laravel.
            static fn (string $clientId): bool => $clientId !== '',
        );
        $fake->assertInvoked(
            GameDirectory::class,
            'register',
            static fn (string $gameId): bool => $gameId === $id,
        );
    }

    public function testTheSeatKeyIgnoresAnythingTheBrowserSends(): void
    {
        $fake = Atoms::fake([
            MancalaGame::class => ['create' => ['status' => 'waiting', 'revision' => 0]],
            GameDirectory::class => ['register' => null],
        ]);

        $this->withSession([PlayerIdentity::SESSION_KEY => 'seat-key-one'])
            ->postJson('/api/games', ['client_id' => 'someone-elses-seat'])
            ->assertCreated();

        $fake->assertInvoked(
            MancalaGame::class,
            'create',
            static fn (string $clientId): bool => $clientId === 'seat-key-one',
        );
    }

    public function testAnEstablishedSessionKeepsItsSeat(): void
    {
        $fake = Atoms::fake([
            MancalaGame::class => ['create' => ['status' => 'waiting', 'revision' => 0]],
            GameDirectory::class => ['register' => null],
        ]);

        $this->withSession([PlayerIdentity::SESSION_KEY => 'seat-key-one'])
            ->postJson('/api/games')
            ->assertCreated();

        $fake->assertInvoked(
            MancalaGame::class,
            'create',
            static fn (string $clientId): bool => $clientId === 'seat-key-one',
        );
    }

    public function testAPlayerGetsASignedSocketUrlForTheirGame(): void
    {
        // Tickets are signed locally, so the real issuer runs here.
        $this->configureAtoms();
        $gameId = str_repeat('a', 32);

        $url = (string) $this->withSession([PlayerIdentity::SESSION_KEY => 'seat-key-one'])
            ->postJson('/api/games/' . $gameId . '/ticket')
            ->assertOk()
            ->assertJsonStructure(['url'])
            ->json('url');

        self::assertStringStartsWith('wss://worker.example/ws/MancalaGame/' . $gameId . '?', $url);

        // The seat rides inside the signed ticket, so these three are the whole
        // query string.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['channels', 'mode', 'ticket'], array_keys($query));
        self::assertSame('game', $query['channels']);
        self::assertSame('player', $query['mode']);
        self::assertStringStartsWith('v1.', (string) $query['ticket']);
    }

    public function testAnObserverAsksForAnObserverUrl(): void
    {
        $this->configureAtoms();
        $gameId = str_repeat('c', 32);

        $url = (string) $this->postJson('/api/games/' . $gameId . '/ticket?observe=1')
            ->assertOk()
            ->json('url');

        self::assertStringContainsString('mode=observe', $url);
    }

    public function testTheTicketClaimIsTheSessionSeatAndNothingTheBrowserSends(): void
    {
        $this->configureAtoms();
        $fake = Atoms::fake();
        $gameId = str_repeat('a', 32);

        $this->withSession([PlayerIdentity::SESSION_KEY => 'seat-key-one'])
            ->postJson('/api/games/' . $gameId . '/ticket', ['client_id' => 'someone-elses-seat'])
            ->assertOk();

        // The assertion the unforgeable-seat design rests on: the browser had no
        // way to influence this value.
        $fake->assertTicketIssued(
            MancalaGame::class,
            $gameId,
            static fn (array $claims): bool => $claims === ['client_id' => 'seat-key-one'],
        );
    }

    public function testTicketRouteRejectsAMalformedGameId(): void
    {
        Atoms::fake();

        $this->postJson('/api/games/not-a-game-id/ticket')->assertNotFound();
    }

    public function testTicketIssuerResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(TicketIssuer::class, $this->app->make(TicketIssuer::class));
    }

    /**
     * Point the client at a known endpoint with a valid secret. Each of these is
     * a singleton resolved from config, so the cached instances go with it.
     */
    private function configureAtoms(): void
    {
        config([
            'atoms.shared_secret' => base64_encode(str_repeat('k', 32)),
            'atoms.endpoint' => 'https://worker.example',
        ]);

        foreach ([AtomsConfig::class, TicketIssuer::class, AtomsClient::class, AtomsManager::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
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
