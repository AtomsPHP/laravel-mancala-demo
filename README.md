# Atoms Mancala — Laravel demo

This is a complete two-player Mancala application built with Laravel, Vue, and
two persistent Atoms. A `MancalaGame` owns one board and its live WebSocket
connections. A singleton `GameDirectory` powers the public list of games that
can be observed.

The first browser creates the table and becomes Player 1. The next distinct
browser to open the shared URL becomes Player 2; later visitors observe. Moves
go straight to the game Atom over its WebSocket and are broadcast as an ordered
stone path, so every browser animates the same move from the same durable turn.

## What the example demonstrates

- one PHP object and one SQLite database per game;
- serialized turns that keep moves in order;
- live `onConnect()`, `onMessage()`, and `broadcast()` behavior;
- durable player identity across socket hibernation and reconnects;
- a named alarm that purges game data after 24 hours, while finished games keep
  their board for review and leave discovery immediately;
- a Laravel `AtomJob` that keeps a second directory Atom eventually consistent;
- local, network-free testing through `AtomHarness` and `Atoms::fake()`.

The home page syntax-highlights the actual `MancalaGame.php` and
`GameDirectory.php` source through Vite raw imports, so the displayed code is
the code that ships to the Worker.

## Install

```sh
git clone https://github.com/AtomsPHP/laravel-mancala-demo.git
cd laravel-mancala-demo
composer install
npm ci
cp .env.example .env
php artisan key:generate
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.1.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
```

Composer resolves the Atoms packages from the public `AtomsPHP/*` package
mirrors.

## Run locally

Use three terminals:

```sh
# Laravel
php artisan serve

# Vue/Vite
npm run dev

# Build the Atoms and run the local Worker
vendor/bin/atoms dev
```

Open `http://127.0.0.1:8000`, create a game, and open its shared URL in a
second browser profile. Open the home-page Watch link in a third profile to
verify observer mode. The two player browsers should animate every move in the
same order.

## Deploy

Both halves run in your own accounts. Laravel Cloud serves the Vue application
and a small creation, discovery, and signed-callback API. A Cloudflare Worker
runs `MancalaGame` and `GameDirectory`, and browsers send all gameplay straight
to that Worker over WebSockets.

Deploy Laravel first, so its callback URL is publicly reachable. Then run
**Deploy Atoms** followed by **Configure Callback Channel** from this
repository's Actions tab, and point Laravel's `ATOMS_ENDPOINT` at the published
Worker URL. [`docs/deployment.md`](docs/deployment.md) has the full setup.

## Verify

```sh
composer test
vendor/bin/phpstan analyse --debug --memory-limit=1G
vendor/bin/atoms validate
vendor/bin/atoms build
npm test
npm run build
```

All gameplay tests use real SQLite migrations. They cover seating, reconnects,
observers, legal and stale moves, both stores, free turns, captures, sweeping,
wins, draws, broadcasts, listing jobs, disconnects, and expiry purging through
`AtomHarness` and Laravel fakes.
