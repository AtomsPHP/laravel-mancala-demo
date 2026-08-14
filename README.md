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
- serialized moves without application-level locking;
- live `onConnect()`, `onMessage()`, and `broadcast()` behavior;
- durable player identity across socket hibernation and reconnects;
- a named alarm that purges game data after 24 hours;
- a Laravel `AtomJob` that keeps a second directory Atom eventually consistent;
- local, network-free testing through `AtomHarness` and `Atoms::fake()`.

The home page syntax-highlights the actual `MancalaGame.php` and
`GameDirectory.php` source through Vite raw imports. There is no duplicate
marketing snippet to drift away from the executable example.

## Install

Clone the standalone demo, then install it:

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

The Composer repositories point at the public `AtomsPHP/*` package mirrors, so
the application has no dependency on the Atoms monorepo.

Set `ATOMS_ENDPOINT` to the Worker URL. Leave `ATOMS_API_KEY` completely unset:
the browser WebSocket API cannot attach the Worker's bearer header, so this
public demo intentionally deploys with `ATOMS_APP_KEY` unset as well. Game and
browser IDs are random, but this is a demonstration posture—not authentication
for sensitive data.

The listing update job uses the signed callback channel. Configure the
Worker's callback URL/signing key and place the matching public key in
`ATOMS_PLATFORM_PUBLIC_KEY`. Keep `QUEUE_CONNECTION=sync` for the smallest
demo deployment, or use any normal Laravel queue worker.

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

Fill in the production account, endpoint, callback URL, and Worker directory in
`atoms.json`. Export `CLOUDFLARE_API_TOKEN`, then run:

```sh
npm run build
vendor/bin/atoms deploy --env production
```

Deploy the Laravel application separately with its built `public/build`
assets. The included `.github/workflows/deploy-atoms.yml` shows the Atom half
using the immutable `AtomsPHP/atoms/action@v0.1.0` release.

### Laravel Cloud

Create a Laravel Cloud application from this GitHub repository and select PHP
8.3 or newer. In the environment's build commands, install the locked PHP and
JavaScript dependencies and compile the frontend:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

Set `APP_KEY`, `APP_URL`, `ATOMS_ENDPOINT`, and
`ATOMS_PLATFORM_PUBLIC_KEY` in the Laravel Cloud environment. Keep
`QUEUE_CONNECTION=sync` for this demo. Point `atoms.json`'s production
callback URL at `https://<your-cloud-domain>/atoms/callback` before deploying
the Worker, and store the matching Ed25519 signing seed as the Worker's
`ATOMS_CALLBACK_SIGNING_KEY` secret.

Laravel Cloud can deploy the application whenever `main` changes. The separate
`Deploy Atoms` GitHub workflow is intentionally manual, because deploying PHP
objects into the Cloudflare Worker is an independent release boundary.

Games retain their finished board for review but disappear from discovery as
soon as they finish. At the configured lifetime—24 hours by default—the game's
alarm removes pits, players, and connection identity, leaving only an expired
marker. The current Worker has no physical Durable Object deletion route.

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
wins, draws, broadcasts, listing jobs, disconnects, and expiry purging without
a Cloudflare account.
