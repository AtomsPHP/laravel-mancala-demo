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
| `ATOMS_CALLBACK_SIGNING_KEY` | GitHub Actions secret | Private Ed25519 seed provisioned into the Worker |
| `ATOMS_PLATFORM_PUBLIC_KEY` | Laravel Cloud environment | Verifies Worker callbacks in Laravel |
| `ATOMS_ENDPOINT` | Laravel Cloud environment | Public URL of your deployed Worker |
| `callback_url.production` | `atoms.json` | Public Laravel Cloud callback URL |

The callback channel takes **two** Worker-side values, and `atoms deploy` sends
neither: `ATOMS_CALLBACK_URL` (where the Worker reaches Laravel) and
`ATOMS_CALLBACK_SIGNING_KEY` (how Laravel proves the caller was the Worker).
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
QUEUE_CONNECTION=sync

ATOMS_ENVIRONMENT=production
ATOMS_ENDPOINT=https://atoms-mancala-demo.your-workers-subdomain.workers.dev
ATOMS_PLATFORM_PUBLIC_KEY=...
ATOMS_CALLBACK_PATH=/atoms/callback
```

`QUEUE_CONNECTION=sync` is the smallest deployment that works; any normal
Laravel queue worker is fine instead. Changing Laravel Cloud environment
variables requires a new deployment.

## 2. Generate the callback key pair

Run this once on a trusted machine with PHP's Sodium extension:

```sh
php -r '$seed=random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES); $pair=sodium_crypto_sign_seed_keypair($seed); echo "ATOMS_CALLBACK_SIGNING_KEY=".base64_encode($seed).PHP_EOL; echo "ATOMS_PLATFORM_PUBLIC_KEY=".base64_encode(sodium_crypto_sign_publickey($pair)).PHP_EOL;'
```

Save the two outputs immediately:

- Put `ATOMS_PLATFORM_PUBLIC_KEY` in Laravel Cloud.
- Put `ATOMS_CALLBACK_SIGNING_KEY` in this repository's GitHub Actions secrets.

If the private seed is lost, generate a new pair and rotate both values.

## 3. Configure the production addresses

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

The GitHub workflow supplies `account_id` from its Actions secret. Commit the
public endpoint and callback URL.

## 4. Give GitHub Actions permission to deploy the Worker

In Cloudflare, create an account-scoped API token using the **Edit Cloudflare
Workers** permission and restrict it to the account that will host the demo.
Copy the account ID from that same account.

In this GitHub repository, open **Settings → Secrets and variables → Actions**
and create:

- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `ATOMS_CALLBACK_SIGNING_KEY`

The deployment workflow passes the Cloudflare credentials to the immutable
`AtomsPHP/atoms/action@v0.1.0` deploy action. The callback workflow sends the
private seed to Wrangler over stdin.

## 5. Deploy both halves

First, deploy Laravel Cloud so the callback URL is publicly reachable. A push
to `main` normally triggers this when push-to-deploy is enabled.

Then open this repository's **Actions → Deploy Atoms → Run workflow**. The
workflow builds the two Atoms, initializes the release-matched Worker runtime,
and deploys `atoms-mancala-demo` into your Cloudflare account.

After that first deployment, open **Actions → Configure Callback Channel → Run
workflow**. It provisions both halves of the channel into the deployed Worker:
`ATOMS_CALLBACK_URL`, taken from `atoms.json`'s `callback_url.production`, and
`ATOMS_CALLBACK_SIGNING_KEY`, taken from GitHub Actions secrets. Both are
stored as Worker secrets rather than `wrangler.jsonc` vars, because the deploy
action regenerates the Worker directory on every run.

Run it again after changing the Laravel Cloud URL, and during callback key
rotation; `wrangler secret put` creates and deploys a new Worker version.

Copy the Worker URL into Laravel Cloud as `ATOMS_ENDPOINT` and redeploy Laravel.

## 6. Verify the deployment

1. Visit `<ATOMS_ENDPOINT>/healthz` and confirm it returns `{"ok":true}`.
2. Visit the Laravel Cloud URL and create a game.
3. Open the shared URL in a different browser profile; it should claim Player
   2 and start the game.
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
