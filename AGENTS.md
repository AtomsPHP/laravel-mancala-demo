# AGENTS.md

Guidance for coding agents working in this repository. `README.md` is the
public introduction and is deliberately short; the operational detail lives in
`docs/`.

- [`docs/deployment.md`](docs/deployment.md) — the two deployment targets, the
  secret and configuration map, and the ordered setup steps.
- [`docs/troubleshooting.md`](docs/troubleshooting.md) — diagnosing the signed
  callback channel, and the Worker's public-surface posture.

Keep the README free of debugging procedure, secret tables, and environment
dumps. Add that material here or under `docs/`.

## What this is

A two-player Mancala demo for Atoms: Laravel and Vue in front, two Atoms on a
Cloudflare Worker behind. `app/Atoms/MancalaGame.php` owns one board and its
sockets; `app/Atoms/GameDirectory.php` is a singleton index of games that can be
observed. Browsers send gameplay straight to the Worker over WebSockets, so
Laravel is not in the move path.

## Rules that outlive any one change

- **The lobby is an index, never the truth.** `GameDirectory` rows can be stale
  by design. A game's own `snapshot()` is authoritative, and lobby reads verify
  against it before listing anything.
- **The callback channel is best effort at the call site.**
  `MancalaGame::queueListingUpdate()` swallows dispatch failures on purpose:
  discovery must never break a turn. Anything that must not be lost cannot ride
  on it.
- **No capacity constants in code.** Limits, lifetimes, and intervals live in
  `config/mancala.php` with `MANCALA_*` env defaults.
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
npm test
```

Gameplay tests run real SQLite migrations through `AtomHarness`; Laravel-side
tests use `Atoms::fake()`. Neither needs a Cloudflare account.
