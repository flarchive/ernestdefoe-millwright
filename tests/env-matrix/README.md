# Environment matrix

Millwright has to work wherever Flarum does. This script installs Flarum 2 the
four ways people actually install it, then on each one updates an extension and
undoes the update, checking at every step that the forum still answers.

| Environment | What it stands for |
|---|---|
| `fiab` | [Flarum-in-a-box](https://discuss.flarum.org/d/39191): Flarum baked **into** the Docker image, on overlayfs. Folders that came from the image cannot be renamed. |
| `docker` | A standard Composer-built Flarum 2 image ([linkrobins/flarum-docker](https://github.com/linkrobins/flarum-docker)): the app on a volume, Redis (Valkey) cache, Horizon. |
| `zip` | The [official installation zip](https://docs.flarum.org/2.x/install) in plain PHP + Apache, as on shared hosting: one non-root account, `proc_open`/`exec` disabled, and **only** the web API used, never a shell. |
| `composer-cli`, `composer-web` | `composer create-project`, the recommended route: one forum driven from the CLI, a fresh one driven from the web API. |
| `core-nightly`, `core-nightly-zip` | **Flarum itself:** a forum on the current release moved to the nightly build (`2.x-dev`) through the web API, then undone back to the release. Once on a host that can start processes, once on the zip with `proc_open` disabled. |

Each run starts with **root-owned cache files**, made the way a real forum gets
them: `docker exec … php flarum cache:clear` runs as root. The checks:

- the update finishes, the package's version moves, and the site answers;
- the **after-update health check actually ran**. Inside a container the
  forum's own address is the host's, so this is the check that quietly switches
  itself off;
- the database changes the update ran are **recorded**, and **Undo this update**
  reverses exactly those, restores the old version, and the site answers;
- on `zip`, that `proc_open` really is disabled for the web.

## Running it

It needs Docker and about 15 minutes for every scenario (the nightly round trips are most of it; pick with `--only`). Run it on a server rather than a laptop:
the images pull in seconds there.

```bash
# from a Millwright checkout, on a remote Docker host (packs THIS tree, uncommitted work included)
tests/env-matrix/run.sh --remote root@your-server

# or on a machine with Docker
tests/env-matrix/run.sh

# a subset, or keep the containers afterwards to look around
tests/env-matrix/run.sh --remote root@your-server --only fiab,zip --keep
```

Other options: `--package vendor/name --from <older version>` picks the
extension that gets updated (default `acpl/mobile-tab` from `2.0.0-beta.12`),
and `--zip-url` overrides the installation zip. By default it uses the newest
2.x package Flarum publishes.

It exits 0 when every check passed and 1 otherwise, and ends with a summary
table.

## Safe on a shared host

Everything it creates is named `mwmatrix_*` and published only on
`127.0.0.1:18101`–`18107`. It removes all of it at the end, and leftovers from
an interrupted run at the start. It never touches another container. That's
why the `docker` environment uses its own compose file: the project's own names
its container `flarum_app` and publishes port 80, which a real forum on the
same host may already own.

## What it has caught

Written after these, which all shipped before it existed (2026-10-07):

- overlayfs refusing to rename `vendor/` folders from the image, so **no**
  update could work in Flarum-in-a-box;
- a root-owned cache blocking every update, then blocking "undo" at
  `cache:clear`;
- "undo" leaving PHP running the newer code, so every page returned 500;
- the health check unable to reach the forum from inside its container, which
  disabled the automatic rollback in every Docker install.

And since: undo leaving the database as the newer version changed it, now
reversed through each migration's own `down` step. The same run showed why each
setup needs a fresh forum per cycle: Mobile Tab 2.0.1's `down` leaves behind a
permission row its `up` inserts, so a second update over the same database fails
on the extension's migration. Millwright stopped, undid it and kept the site up,
but that tests the extension, not Millwright.

And from the core scenarios, the same day:

- a web-driven core update could never finish. The moment core's files
  landed, Flarum answered every request, Millwright's step endpoint included,
  with "Update Flarum". The database is now brought up to date in the request
  that swaps core: `php flarum migrate` where a process can be started, Flarum's
  own updater where it can't. Core is swapped last for the same reason;
- undoing a core update failed halfway: nightly's API middleware reads a table
  its own migration creates, so reversing that migration inside the undo request
  broke the response. The reversal now runs on the next request, from the
  restored code, using copies of the migration files kept at update time. Undo
  also restores the version Flarum records, without which the restored release
  showed "Update Flarum".

Run it before releasing anything that touches how files are moved, cached,
migrated or checked.
