# Deploying the demo

The application has two independent deployment targets:

- Laravel Cloud serves the Vue application and the small creation, discovery,
  and signed-callback API.
- A Cloudflare Worker runs `MancalaGame` and `GameDirectory`. Browsers send all
  gameplay directly to this Worker over WebSockets.

Laravel Cloud's source integration reads the repository, and push-to-deploy can
deploy `main`. Runtime values are divided between Laravel Cloud, GitHub
Actions, and `atoms.json`.

## Secret and configuration map

| Value | Put it here | Purpose |
| --- | --- | --- |
| `APP_KEY` | Laravel Cloud environment | Laravel encryption key |
| `CLOUDFLARE_API_TOKEN` | GitHub Actions secret | Lets the deploy workflow publish the Worker |
| `CLOUDFLARE_ACCOUNT_ID` | GitHub Actions secret | Selects your Cloudflare account |
| `ATOMS_ENDPOINT` | Laravel Cloud environment | Public URL of your deployed Worker |
| `ATOMS_SHARED_SECRET` | GitHub Actions secret **and** Laravel Cloud environment | The one secret on the Laravel ↔ Worker boundary |
| `callback_url.production` | `atoms.json` | Public Laravel Cloud callback URL |

`ATOMS_SHARED_SECRET` is base64 of 32 random bytes, byte-identical on both
sides, and it is the only credential in this deployment. Everything that
crosses the boundary is HKDF-SHA256 derived from it:

- the `Authorization: Bearer` value Laravel sends and the Worker compares,
- the key the Worker signs WebSocket connection tickets with,
- the HMAC-SHA256 key the Worker signs callbacks with and Laravel verifies.

**It is not a bearer token and must never be sent as one.** The derived bearer
is what travels; HKDF's one-wayness means a bearer captured from a proxy log or
an APM trace cannot be walked back to the secret. Run `atoms token` to print
the derived bearer when you need to call the Worker by hand.

Rotating it invalidates every outstanding WebSocket ticket, by design — a
reconnecting browser mints a fresh one. For a zero-downtime rotation, set the
old value as `ATOMS_SHARED_SECRET_PREVIOUS` alongside the new one on both sides
during the overlap, then remove it once both sides hold the new secret.

The callback channel takes **two** Worker-side values, and `atoms deploy` sends
neither: `ATOMS_CALLBACK_URL` (where the Worker reaches Laravel) and
`ATOMS_SHARED_SECRET` (which the Worker derives its callback signing key from).
The **Configure Callback Channel** workflow provisions both — it reads the URL
from `atoms.json`, so that file is the single source of truth. Until it runs,
`dispatch()` fails closed with ATOMS-E080 and no game reaches the lobby.

## 1. Configure Laravel Cloud

Create an application from this repository, select PHP 8.3 or newer and Node
22, and use these build commands:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

Generate a Laravel application key locally:

```sh
php artisan key:generate --show
```

Add these values under the Laravel Cloud environment's variables. After the
Worker deployment, set `ATOMS_ENDPOINT` to its published URL.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://your-app.laravel.cloud
LOG_CHANNEL=stderr
SESSION_DRIVER=file
SESSION_LIFETIME=1440
QUEUE_CONNECTION=sync

ATOMS_ENVIRONMENT=production
ATOMS_ENDPOINT=https://atoms-mancala-demo.your-workers-subdomain.workers.dev
ATOMS_CALLBACK_PATH=/atoms/callback
ATOMS_SHARED_SECRET=...
```

`QUEUE_CONNECTION=sync` is the smallest deployment that works; any normal
Laravel queue worker is fine instead. Changing Laravel Cloud environment
variables requires a new deployment.

The session **is** the player's seat, so `SESSION_LIFETIME` has to outlive a
game — Laravel's 120-minute default would reseat anyone who stepped away from a
24-hour table. `SESSION_DRIVER=file` on an ephemeral container is the matching
compromise: identity is lost on redeploy or an instance change. It is fine for a
demo, and a real application would put sessions in a shared store.

## 2. Generate the shared secret

Run this once on a trusted machine:

```sh
openssl rand -base64 32
```

Put that single value in both places:

- `ATOMS_SHARED_SECRET` in the Laravel Cloud environment.
- `ATOMS_SHARED_SECRET` in this repository's GitHub Actions secrets.

They must be byte-identical. If they disagree, Laravel's calls are rejected and
its callback verification fails; if the Worker has no valid secret at all, every
route but `GET /healthz` answers `misconfigured` (HTTP 500). There is no
"unset" posture — the secret is required on both sides.

Do not paste this value into an `Authorization` header. `atoms token` prints
the bearer derived from it, which is what the wire actually carries.

## 3. Configure the production addresses

Edit `atoms.json` before deploying:

```json
{
  "environments": {
    "production": {
      "endpoint": "https://atoms-mancala-demo.your-workers-subdomain.workers.dev",
      "worker_name": "atoms-mancala-demo",
      "account_id": "",
      "debug_endpoints": false
    }
  },
  "callback_url": {
    "production": "https://your-app.laravel.cloud/atoms/callback"
  }
}
```

The GitHub workflow supplies `account_id` from its Actions secret. Commit the
public endpoint and callback URL.

`debug_endpoints` is the durable switch for the Worker's `/debug/*` routes, and
the only one that survives a deploy — `.atoms/worker` is gitignored and the
deploy action regenerates it on every run, so editing the Worker directory's
`wrangler.jsonc` does nothing. It is `false` for production here and `true` for
`staging`, which is the local `atoms dev` target. Flip production to `true` and
redeploy when you need `/debug/:type/:id/info` against the live Worker; the
route stays behind the bearer check either way.

## 4. Give GitHub Actions permission to deploy the Worker

In Cloudflare, create an account-scoped API token using the **Edit Cloudflare
Workers** permission and restrict it to the account that will host the demo.
Copy the account ID from that same account.

In this GitHub repository, open **Settings → Secrets and variables → Actions**
and create:

- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `ATOMS_SHARED_SECRET` — the value from step 2, identical to Laravel Cloud's

The deployment workflow passes the Cloudflare credentials to the immutable
`AtomsPHP/atoms/action@v0.3.1` deploy action. The callback workflow sends the
shared secret to Wrangler over stdin, after checking it decodes to 32 bytes.

## 5. Deploy both halves

First, deploy Laravel Cloud so the callback URL is publicly reachable. A push
to `main` normally triggers this when push-to-deploy is enabled.

Then open this repository's **Actions → Deploy Atoms → Run workflow**. The
workflow builds the two Atoms, initializes the release-matched Worker runtime,
and deploys `atoms-mancala-demo` into your Cloudflare account.

After that first deployment, open **Actions → Configure Callback Channel → Run
workflow**. It provisions both halves of the channel into the deployed Worker:
`ATOMS_CALLBACK_URL`, taken from `atoms.json`'s `callback_url.production`, and
`ATOMS_SHARED_SECRET`, taken from GitHub Actions secrets. Both are stored as
Worker secrets rather than `wrangler.jsonc` vars, because the deploy action
regenerates the Worker directory on every run.

Run it again after changing the Laravel Cloud URL, and during secret rotation;
`wrangler secret put` creates and deploys a new Worker version.

Copy the Worker URL into Laravel Cloud as `ATOMS_ENDPOINT` and redeploy Laravel.

### Getting the two sides into agreement

A freshly deployed Worker with no `ATOMS_SHARED_SECRET` answers `misconfigured`
on every route but `GET /healthz` — loudly broken, never silently open. The
moment the secret lands it starts checking bearers, so Laravel must already
hold the identical value or there is a window where the site is up and nothing
works.

Before you start: **this deployment resets every seat in every live game.**
Seats are keyed on the player id, which moves from a browser `localStorage`
value to the Laravel session in this release. A player returning to a game
started before the deploy presents a new identity, matches no row, finds both
seats taken, and silently becomes an observer of their own game — for the rest
of that game's 24 hours. There is no migration for it; deploy when no one is
playing.

1. Generate the secret once: `openssl rand -base64 32`.
2. Set `ATOMS_SHARED_SECRET` to that value in Laravel Cloud and **redeploy
   Laravel**. Nothing works against the Worker yet, which is expected.
3. Add `ATOMS_SHARED_SECRET`, the same value, as a GitHub Actions secret.
4. Run **Deploy Atoms**, which publishes the runtime that serves `/tickets`.
5. Run **Configure Callback Channel**. Both sides now agree.
6. Confirm it took:

   ```sh
   curl -s -o /dev/null -w '%{http_code}\n' \
     -X POST "$ATOMS_ENDPOINT/invoke/MancalaGame/probe"
   ```

   `401` is the answer you want: the route is reachable and refusing an
   unauthenticated caller. `500` means the Worker has no valid secret.

Rotation is the same dance, and the two sides must never disagree for longer
than a deploy takes. To do it without downtime, set `ATOMS_SHARED_SECRET_PREVIOUS`
to the old value on both sides first, cut `ATOMS_SHARED_SECRET` over on both,
then drop `ATOMS_SHARED_SECRET_PREVIOUS`. Outstanding WebSocket tickets are
invalidated at the cutover regardless; browsers re-mint on reconnect.

## 6. Verify the deployment

1. Visit `<ATOMS_ENDPOINT>/healthz` and confirm it returns `{"ok":true}`.
   A headerless `POST /invoke/...` should answer `401` — a `500` there means
   the Worker never got a valid `ATOMS_SHARED_SECRET`.
2. Visit the Laravel Cloud URL and create a game.
3. Open the shared URL in a different browser **profile**; it should claim
   Player 2 and start the game. A profile is a session is a player, so a second
   tab in the same profile shares the first seat rather than taking a new one.
4. Open the same URL with `?observe=1` in a third profile.
5. Make a move and confirm all three boards animate the same ordered drops.
6. Return home and confirm the started game appears under **Tables in motion**.

A game that starts but never reaches the lobby means the signed callback
channel is not delivering: rerun **Configure Callback Channel**, then confirm
Laravel routes `POST /atoms/callback`.

## Further reading

[Laravel Cloud environments](https://cloud.laravel.com/docs/environments),
[Cloudflare GitHub Actions authentication](https://developers.cloudflare.com/workers/ci-cd/external-cicd/github-actions/),
[Cloudflare Worker secrets](https://developers.cloudflare.com/workers/configuration/secrets/),
and [the Atoms callback-channel contract](https://github.com/AtomsPHP/atoms/blob/main/docs/cloudflare-toolchain.md#the-callback-channels-two-variables-m2).
