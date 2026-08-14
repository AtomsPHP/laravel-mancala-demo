# Troubleshooting

## A started game never appears under "Tables in motion"

The lobby lists directory rows whose status is `active`. Nothing observes a game
starting: `MancalaGame::onConnect()` dispatches an `UpdateGameListing` job when
seat 1 is taken, and that job runs in Laravel, reached over the signed callback
channel. If the channel is down the row stays `waiting` and the game is
invisible — silently, because `queueListingUpdate()` swallows dispatch failures
by design.

Ask the Worker first, with debug endpoints enabled:

```sh
curl -s "$ATOMS_ENDPOINT/debug/GameDirectory/public-mancala-lobby/info" \
  | jq '.info | {callback_channel, dispatches_this_residency, dispatch_failures_this_residency}'
```

- `unconfigured` — `ATOMS_CALLBACK_URL` is unset.
- `misconfigured` — the URL is set but the signing key is unusable.

Both are fixed by rerunning **Configure Callback Channel**.

A `configured` channel with a rising `dispatch_failures_this_residency` points
at Laravel instead. Check that `POST /atoms/callback` is routed — an unsigned
request should answer `401 ATOMS-E064`, not `404` — that
`ATOMS_PLATFORM_PUBLIC_KEY` matches the seed, and the Laravel Cloud logs.

Fixing the channel is not retroactive. A game that was live while the channel
was down stays listed as `waiting` for the rest of its lifetime, because the
lobby only re-verifies rows it already believes are `active`.

## Debug endpoints

`/debug/:type/:id/info` is available only when the Worker runs with
`ATOMS_DEBUG_ENDPOINTS=1`, which the runtime's default `wrangler.jsonc` sets.
It is gated by that flag alone, so on a public deployment it is public.

The same applies to `/invoke` and `/ws`: the Worker's bearer check is skipped
entirely when `ATOMS_APP_KEY` is unset, and this demo cannot set it. Browsers
cannot attach an `Authorization` header to a WebSocket handshake, and the
browser talks to the Worker directly. Atoms records this as a known gap, with
connection tickets as the designated answer.
