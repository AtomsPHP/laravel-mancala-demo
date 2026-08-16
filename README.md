# Atoms Mancala — Laravel demo

This is a complete two-player Mancala application built with Laravel, Vue, and
two persistent Atoms. A `MancalaGame` owns one board and its live WebSocket
connections. A singleton `GameDirectory` powers the public list of games that
can be observed.

The first visitor creates the table and becomes Player 1. The next browser
profile to open the shared URL becomes Player 2; later visitors observe. A seat
belongs to a Laravel session, so two tabs in one profile share it. Moves go
straight to the game Atom over its WebSocket and are broadcast as an ordered
stone path, so every browser animates the same move from the same durable turn.

## What the example demonstrates

- one PHP object and one SQLite database per game;
- serialized turns that keep moves in order;
- live `onConnect()`, `onMessage()`, and `broadcast()` behavior;
- durable player identity across socket hibernation and reconnects;
- signed connection tickets, so the Worker can require auth even though a
  browser cannot set a header on a WebSocket handshake;
- a named alarm that purges game data 24 hours after creation; a finished game
  leaves discovery at once but keeps its board until that alarm fires;
- a Laravel `AtomJob` that keeps a second directory Atom eventually consistent.

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
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.2.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
```

Composer resolves the Atoms packages from Packagist.

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

Re-run the `init` step above after pulling; `.atoms/worker` is gitignored and
its version is never checked.

`atoms dev` generates a per-machine `ATOMS_SHARED_SECRET` into
`.atoms/worker/.dev.vars` on first run and prints the path. Copy that value into
your `.env` as `ATOMS_SHARED_SECRET` — Laravel and the Worker must hold the same
one locally exactly as in production.

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
composer stan
vendor/bin/atoms validate
vendor/bin/atoms build
npm test
npm run build
```

Nothing here needs a Cloudflare account. The tests cover the Laravel routes with
`Atoms::fake()`, and the Vue board with Vitest — including its ticket and
reconnect lifecycle.
