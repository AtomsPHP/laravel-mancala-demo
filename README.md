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

## Deploy to Laravel Cloud and Cloudflare

The application has two independent deployment targets:

- Laravel Cloud serves the Vue application and the small creation, discovery,
  and signed-callback API.
- A Cloudflare Worker runs `MancalaGame` and `GameDirectory`. Browsers send all
  gameplay directly to this Worker over WebSockets.

Connecting this repository to Laravel Cloud does **not** require you to invent
a shared secret between GitHub and Laravel Cloud. Laravel Cloud's source
integration reads the repository and push-to-deploy can deploy `main`. The
secrets below belong to the running Laravel application, GitHub Actions, or the
Worker—not to the source repository.

### Secret and configuration map

| Value | Put it here | Secret? | Purpose |
| --- | --- | --- | --- |
| `APP_KEY` | Laravel Cloud environment | Yes | Laravel encryption key |
| `CLOUDFLARE_API_TOKEN` | GitHub Actions secret | Yes | Lets the deploy workflow publish the Worker |
| `CLOUDFLARE_ACCOUNT_ID` | GitHub Actions secret | No | Selects your Cloudflare account |
| `ATOMS_CALLBACK_SIGNING_KEY` | Cloudflare Worker secret | Yes | Private Ed25519 seed used to sign callbacks |
| `ATOMS_PLATFORM_PUBLIC_KEY` | Laravel Cloud environment | No | Verifies Worker callbacks in Laravel |
| `ATOMS_ENDPOINT` | Laravel Cloud environment | No | Public URL of your deployed Worker |
| `callback_url.production` | `atoms.json` | No | Public Laravel Cloud callback URL |

Never commit any of the secret values. This public demo deliberately leaves
both `ATOMS_APP_KEY` and `ATOMS_API_KEY` unset: standard browser WebSockets
cannot attach the Worker's optional bearer header.

### 1. Configure Laravel Cloud

Create an application from this repository, select PHP 8.3 or newer and Node
22, and use these build commands:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

There is no Laravel database migration or deploy command for this demo; every
game's SQLite database belongs to its Atom.

Generate a Laravel application key locally:

```sh
php artisan key:generate --show
```

Add these values under the Laravel Cloud environment's variables. You can add
`ATOMS_ENDPOINT` after the Worker has been deployed if its URL is not known
yet.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://your-app.laravel.cloud
LOG_CHANNEL=stderr
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

ATOMS_ENVIRONMENT=production
ATOMS_ENDPOINT=https://atoms-mancala-demo.your-workers-subdomain.workers.dev
ATOMS_PLATFORM_PUBLIC_KEY=...
ATOMS_CALLBACK_PATH=/atoms/callback
```

Changing Laravel Cloud environment variables requires a new deployment.

### 2. Generate the callback key pair

Run this once on a trusted machine with PHP's Sodium extension:

```sh
php -r '$seed=random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES); $pair=sodium_crypto_sign_seed_keypair($seed); echo "ATOMS_CALLBACK_SIGNING_KEY=".base64_encode($seed).PHP_EOL; echo "ATOMS_PLATFORM_PUBLIC_KEY=".base64_encode(sodium_crypto_sign_publickey($pair)).PHP_EOL;'
```

Save the two outputs immediately:

- Put `ATOMS_PLATFORM_PUBLIC_KEY` in Laravel Cloud.
- Put `ATOMS_CALLBACK_SIGNING_KEY` in the Cloudflare Worker as a secret after
  its first deployment. Do not put the private seed in Laravel Cloud or the
  repository.

If the private seed is lost, generate a new pair and rotate both values.

### 3. Configure the production addresses

Edit `atoms.json` before deploying:

```json
{
  "environments": {
    "production": {
      "endpoint": "https://atoms-mancala-demo.your-workers-subdomain.workers.dev",
      "worker_name": "atoms-mancala-demo",
      "account_id": ""
    }
  },
  "callback_url": {
    "production": "https://your-app.laravel.cloud/atoms/callback"
  }
}
```

The account ID may remain empty in this file because the GitHub workflow
supplies it securely. Commit the public endpoint and callback URL.

### 4. Give GitHub Actions permission to deploy the Worker

In Cloudflare, create an account-scoped API token using the **Edit Cloudflare
Workers** permission and restrict it to the account that will host the demo.
Copy the account ID from that same account.

In this GitHub repository, open **Settings → Secrets and variables → Actions**
and create:

- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`

Do not put either Cloudflare credential in Laravel Cloud. The manual
`.github/workflows/deploy-atoms.yml` workflow passes them only to the immutable
`AtomsPHP/atoms/action@v0.1.0` deploy action.

### 5. Deploy both halves

First, deploy Laravel Cloud so the callback URL is publicly reachable. A push
to `main` normally triggers this when push-to-deploy is enabled.

Then open this repository's **Actions → Deploy Atoms → Run workflow**. The
workflow builds the two Atoms, initializes the release-matched Worker runtime,
and deploys `atoms-mancala-demo` into your Cloudflare account.

After the first Worker deployment, add a Worker secret named
`ATOMS_CALLBACK_SIGNING_KEY` in the Cloudflare dashboard and paste only the
private seed value generated in step 2. Cloudflare also supports provisioning
it with Wrangler:

```sh
cd .atoms/worker
printf '%s' "$ATOMS_CALLBACK_SIGNING_KEY" | npx wrangler secret put ATOMS_CALLBACK_SIGNING_KEY --name atoms-mancala-demo
```

If you did not know the Worker URL earlier, copy it into Laravel Cloud as
`ATOMS_ENDPOINT` and redeploy Laravel. Do not set `ATOMS_API_KEY` for this
browser-accessible demo.

### 6. Verify the deployment

1. Visit `<ATOMS_ENDPOINT>/healthz` and confirm it returns `{"ok":true}`.
2. Visit the Laravel Cloud URL and create a game.
3. Open the shared URL in a different browser profile; it should claim Player
   2 and start the game.
4. Open the same URL with `?observe=1` in a third profile.
5. Make a move and confirm all three boards animate the same ordered drops.
6. Return home and confirm the started game appears under **Tables in motion**.

If games work but never appear in the lobby, the WebSocket path is healthy but
the signed callback path is not. Check the Worker callback URL, the two halves
of the callback key pair, and the Laravel Cloud logs. A `401` from the Worker
usually means `ATOMS_APP_KEY` was set even though this browser demo requires it
to remain unset.

Further reading: [Laravel Cloud environments](https://cloud.laravel.com/docs/environments),
[Cloudflare GitHub Actions authentication](https://developers.cloudflare.com/workers/ci-cd/external-cicd/github-actions/),
[Cloudflare Worker secrets](https://developers.cloudflare.com/workers/configuration/secrets/),
and [the Atoms callback-channel contract](https://github.com/AtomsPHP/atoms/blob/main/docs/cloudflare-toolchain.md#the-callback-channels-two-variables-m2).

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
