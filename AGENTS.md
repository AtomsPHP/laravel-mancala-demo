# AGENTS.md

Guidance for coding agents working in this repository. `README.md` is the
public introduction and is deliberately short; the operational detail lives in
`docs/`.

- [`docs/deployment.md`](docs/deployment.md) — the two deployment targets, the
  secret and configuration map, and the ordered setup steps.
- [`docs/troubleshooting.md`](docs/troubleshooting.md) — diagnosing the signed
  callback channel and the connection-ticket handshake.

Keep the README free of debugging procedure, secret tables, and environment
dumps. Add that material here or under `docs/`.

## What this is

A two-player Mancala demo for Atoms: Laravel and Vue in front, two Atoms on a
Cloudflare Worker behind. `app/Atoms/MancalaGame.php` owns one board and its
sockets; `app/Atoms/GameDirectory.php` is a singleton index of games that can be
observed. Browsers send gameplay straight to the Worker over WebSockets, so
Laravel is not in the move path — but it *is* in the connect path, because only
Laravel can mint the ticket a browser needs to open the socket.

## Working locally

`vendor/bin/atoms dev` uses the `staging` environment in `atoms.json` — that is
the CLI's default `--env`, and the entry exists for local dev only.

`.atoms/worker` is gitignored and nothing compares its version against the
release, so re-run `atoms-runtime-cloudflare init` after pulling. A stale
runtime fails quietly: the board never connects, and because tickets are signed
locally the mint still answers `200`, so the only signal is a socket that opens
and closes.

`ATOMS_SHARED_SECRET` is required locally, not optional. `atoms dev` generates
one into `.env`, adopts one already there, and projects it into the Worker's
gitignored `.dev.vars` — local and production run the identical auth code path,
signed tickets included. A value that differs between the two sides breaks the
board silently: the socket is refused at the upgrade with nothing the browser
can read.

Never send that value as a bearer token; `atoms token` prints the derived one,
reading `.dev.vars` when the variable is not in the environment.

## Rules that outlive any one change

- **The lobby is an index, never the truth.** `GameDirectory` rows can be stale
  by design. A game's own `snapshot()` is authoritative, and lobby reads verify
  against it before listing anything.
- **The callback channel is best effort at the call site.**
  `MancalaGame::queueListingUpdate()` swallows dispatch failures on purpose:
  discovery must never break a turn. Anything that must not be lost cannot ride
  on it.
- **No capacity constants in code.** Limits, lifetimes, and intervals live in
  `config/mancala.php` with `MANCALA_*` env defaults. Board geometry is not a
  capacity: the 12 pits, the 6-per-side split, and the store indices are the
  rules of Mancala and belong exactly where they are. This binds user-facing
  copy too: the game lifetime reaches the Vue layer as a prop, so a string
  never says "24 hours" on its own authority.
- **The seat key is the server's to assert, never the browser's.**
  `App\Support\PlayerIdentity` reads it from the Laravel session, and it reaches
  the Worker only as a signed claim inside a connection ticket. Accepting a
  `client_id` from a request body or a query string would hand anyone the
  ability to take an occupied seat.
- **Atom method signatures are a wire boundary.** Arguments and returns must
  stay inside the serialization algebra — scalars, arrays of them,
  `DateTimeImmutable`, backed enums, `Payload` DTOs.
- **The home page displays real Atom source** via Vite raw imports. Renaming or
  moving those files changes what visitors read.

## Before opening a PR

```sh
composer test
composer stan
vendor/bin/atoms validate
vendor/bin/atoms build
npm test
npm run build
```

The last two lines earn their place. `atoms build` catches a wire-boundary or
`dispatch()` mistake that `validate` alone will not, and `npm run build` is the
*only* command that fails when an Atom file is renamed out from under
`SourcePanel.vue`'s `?raw` imports — every other check still passes. None of
these needs a Cloudflare account.

`tests/Feature/MancalaDemoTest.php` covers the Laravel routes through
`Atoms::fake()`; Vitest covers the Vue board and its ticket/reconnect lifecycle.
`MancalaGame`'s rules engine is not under test, so edit `move()` and its
helpers with care.
