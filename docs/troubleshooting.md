# Troubleshooting

## A started game never appears under "Tables in motion"

The lobby lists directory rows whose status is `active`. Nothing observes a game
starting: `MancalaGame::onConnect()` dispatches an `UpdateGameListing` job when
seat 1 is taken, and that job runs in Laravel, reached over the signed callback
channel. If the channel is down the row stays `waiting` and the game is
invisible — silently, because `queueListingUpdate()` swallows dispatch failures
by design.

Ask the Worker first. `production` ships with debug endpoints off, so this needs
`"debug_endpoints": true` on the environment in `atoms.json` and a redeploy
(see **Debug endpoints** below). The request carries the derived bearer:

```sh
curl -s "$ATOMS_ENDPOINT/debug/GameDirectory/public-mancala-lobby/info" \
  -H "Authorization: Bearer $(atoms token)" \
  | jq '.info | {callback_channel, dispatches_this_residency, dispatch_failures_this_residency}'
```

- `unconfigured` — `ATOMS_CALLBACK_URL` is unset.
- `misconfigured` — the URL is set but rejected: it must be absolute and
  `https:`, or `http:` only for a loopback host. A plain-`http:` callback to a
  public host is the usual cause; the signature protects integrity, never
  confidentiality, so arguments would travel in the clear.

Both are fixed by rerunning **Configure Callback Channel**, which provisions the
callback URL and `ATOMS_SHARED_SECRET` together from `atoms.json` and GitHub
Actions secrets.

A bad *secret* cannot show up here: the Worker's config gate refuses every route
but `GET /healthz` before `/debug` dispatches, so an unusable
`ATOMS_SHARED_SECRET` answers `500 misconfigured` to this curl rather than a
payload with a state in it.

A `configured` channel with a rising `dispatch_failures_this_residency` points
at Laravel instead. Check that `POST /atoms/callback` is routed — an unsigned
request should answer `401 ATOMS-E064`, not `404` — that Laravel's
`ATOMS_SHARED_SECRET` is byte-identical to the Worker's, and the Laravel Cloud
logs.

Fixing the channel is not retroactive. A game that was live while the channel
was down stays listed as `waiting` for the rest of its lifetime, because the
lobby only re-verifies rows it already believes are `active`.

## Debug endpoints

`/debug/:type/:id/info` is off unless the environment sets
`"debug_endpoints": true` in `atoms.json`, which `atoms dev` and `atoms deploy`
forward to Wrangler as `--var ATOMS_DEBUG_ENDPOINTS:1`. Here `staging` — the
local `atoms dev` target — has it on and `production` has it off; flip
production and redeploy when you need it there.

That is the only switch that holds. `.atoms/worker` is gitignored and the
deploy action regenerates it on a fresh checkout, so editing the Worker
directory's `wrangler.jsonc` by hand is overwritten on the next deploy.

The flag is a second gate, not the only one: `/debug` sits behind the Worker's
bearer check like `/invoke`, so enabling it does not expose anything to someone
without the derived bearer. The `?ticket=` exemption is scoped to `/ws`, so a
player's browser holds a valid ticket and still cannot read debug info.

The one case where the flag *is* the only gate is `ATOMS_BEARER_AUTH=disabled`,
which turns the bearer check off for deployments that put an authenticating
proxy such as Cloudflare Access in front of the Worker. This demo does not set
it, so both gates are in force here.

## The WebSocket connection channel

Read this before diagnosing a connection failure — most of the confusing
symptoms below follow from the design.

Every route is behind the bearer check. Browsers cannot attach an
`Authorization` header to a WebSocket handshake, so they do not try. Instead
Laravel — which holds the secret and knows which seat the session owns — mints a
short-lived **connection ticket** scoped to one game at
`POST /api/games/{game}/ticket`, and the browser presents it as `?ticket=` on
the upgrade. The player's `client_id` travels inside that ticket
as a signed claim, and the Worker merges claims *over* the browser's query
parameters, so a browser cannot connect as another player: asking for a ticket
only ever returns your own identity.

Tickets expire in about a minute, so the browser mints a fresh one for every
connection attempt rather than reusing the last. A browser cannot read the
status of a failed upgrade, so there is nothing to diagnose client-side and the
only recovery is to re-mint — which is what a reconnect does.

## The board never connects

Read the mint request in the browser's network tab first, because it is the
only step whose outcome the browser can see.

| Mint answers | What it means |
| --- | --- |
| `500` | Laravel could not build the URL. Almost always `ATOMS_ENDPOINT` unset or missing its `http(s)://` scheme — the exception says which. |
| `419` | The page outlived its session. Reload. |
| `429` | The socket is flapping and hit the per-session mint limit. The real fault is whatever keeps closing it. |
| `404` | The game id is malformed, or `routes/api.php` is not registered. |
| `200`, then the socket closes immediately | The ticket was refused at the upgrade, and **by design the browser cannot see why**. |

The ticket is signed locally, so a mint never calls the Worker: a `200` here
says nothing about whether Laravel and the Worker agree on
`ATOMS_SHARED_SECRET`. That disagreement shows up one step later, as a socket
that opens and closes.

For a ticket refused at the upgrade, run `npx wrangler tail` and look for
`ticket_invalid` (the ticket was signed under a different secret, which is also
what every outstanding ticket looks like just after a rotation, unless
`ATOMS_SHARED_SECRET_PREVIOUS` is still set on the Worker) or `ticket_expired`
(the ticket outlived `ws_ticket_ttl_ms`; expiry is exact, with no skew
allowance).

To check the two secrets match without a browser, invoke an Atom over HTTP. The
bearer is `atoms token`, never the shared secret itself — putting the secret in
a header is exactly what the derivation exists to prevent:

```sh
curl -sS -X POST "$ATOMS_ENDPOINT/invoke/MancalaGame/$GAME/snapshot" \
  -H "Authorization: Bearer $(atoms token)" \
  -H 'content-type: application/json' \
  -d '{"args":[]}'
```

`unauthenticated` means Laravel's `ATOMS_SHARED_SECRET` does not match the
Worker's, so the bearers the two sides derive disagree; `misconfigured` means
the Worker has no usable secret at all.

And confirm the Worker is closed to everyone else:

```sh
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$ATOMS_ENDPOINT/invoke/MancalaGame/probe"
```

`401` is correct. A `500` means the Worker has no valid `ATOMS_SHARED_SECRET`;
rerun **Configure Callback Channel**.

Locally, `atoms dev` generates a per-machine secret into your `.env`, adopts one
already there, and projects it into the Worker's gitignored `.dev.vars` — the
two sides must match locally exactly as they do in production, and local runs
the identical auth code path, signed tickets
included. `atoms token` reads `.dev.vars` when the variable is not in your
environment, so the curl examples above work locally too.
