<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Atoms\GameDirectory;
use App\Atoms\MancalaGame;
use App\Support\PlayerIdentity;
use Atoms\Client\AtomsConfig;
use Atoms\Client\Crypto\KeyDerivation;
use Atoms\Client\Tickets\TicketClient;
use Atoms\Laravel\Facades\Atoms;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

    public function testAPlayerMintsATicketCarryingTheirSessionSeat(): void
    {
        Atoms::fake();
        // AtomsConfig is a singleton that reads this at resolve time, so the
        // cached instance has to go with it.
        $secret = base64_encode(str_repeat('k', 32));
        config(['atoms.shared_secret' => $secret]);
        $this->app->forgetInstance(AtomsConfig::class);
        $this->app->forgetInstance(TicketClient::class);
        $gameId = str_repeat('a', 32);
        $sent = [];
        $this->fakeMintResponses($sent, new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['ticket' => 'v1.signed', 'expires_at' => 1893456000000]),
        ));

        $this->withSession([PlayerIdentity::SESSION_KEY => 'seat-key-one'])
            ->postJson('/api/games/' . $gameId . '/ticket')
            ->assertOk()
            ->assertExactJson(['ticket' => 'v1.signed']);

        self::assertCount(1, $sent);
        self::assertStringEndsWith('/tickets/MancalaGame/' . $gameId, (string) $sent[0]->getUri());
        // The secret itself never travels; the bearer derived from it does.
        self::assertSame(
            'Bearer ' . KeyDerivation::bearerToken($secret),
            $sent[0]->getHeaderLine('Authorization'),
        );
        self::assertStringNotContainsString($secret, $sent[0]->getHeaderLine('Authorization'));

        // The claim is exactly the session's seat and nothing else. This is the
        // assertion the unforgeable-seat design rests on: the browser had no
        // way to influence this value.
        self::assertSame(
            ['claims' => ['client_id' => 'seat-key-one']],
            json_decode((string) $sent[0]->getBody(), true),
        );
    }

    public function testAFailedMintIsOpaqueToTheBrowser(): void
    {
        Atoms::fake();
        $sent = [];
        $this->fakeMintResponses($sent, new Response(
            400,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['error' => ['code' => 'invalid_request', 'retryable' => false]]),
        ));

        // 503, not the Worker's status: a browser cannot read why a WebSocket
        // upgrade failed, so there is nothing for it to branch on but "retry".
        $this->postJson('/api/games/' . str_repeat('b', 32) . '/ticket')
            ->assertStatus(503)
            ->assertJsonMissingPath('error');
    }

    public function testTicketRouteRejectsAMalformedGameId(): void
    {
        Atoms::fake();

        $this->postJson('/api/games/not-a-game-id/ticket')->assertNotFound();
    }

    public function testTicketClientResolvesFromTheContainer(): void
    {
        self::assertInstanceOf(TicketClient::class, $this->app->make(TicketClient::class));
    }

    /**
     * Bind a PSR-18 client that answers every mint with $response, recording the
     * requests into $sent. This drives the real TicketClient rather than a stub,
     * so the request it builds is under test too.
     *
     * @param list<RequestInterface> $sent
     */
    private function fakeMintResponses(array &$sent, Response $response): void
    {
        $this->app->instance(ClientInterface::class, new class ($sent, $response) implements ClientInterface {
            /** @param list<RequestInterface> $sent */
            public function __construct(private array &$sent, private readonly Response $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return $this->response;
            }
        });
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
