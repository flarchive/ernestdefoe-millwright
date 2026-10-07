# Environment matrix

Millwright has to work wherever Flarum does. This script installs Flarum 2 the
four ways people actually install it, then on each one updates an extension and
undoes the update, checking at every step that the forum still answers.

| Environment | What it stands for |
|---|---|
| `fiab` | [Flarum-in-a-box](https://discuss.flarum.org/d/39191): Flarum baked **into** the Docker image, on overlayfs. Folders that came from the image cannot be renamed. |
| `docker` | A standard Composer-built Flarum 2 image ([linkrobins/flarum-docker](https://github.com/linkrobins/flarum-docker)): the app on a volume, Redis (Valkey) cache, Horizon. |
| `zip` | The [official installation zip](https://docs.flarum.org/2.x/install) in plain PHP + Apache, as on shared hosting: one non-root account, `proc_open`/`exec` disabled, and **only** the web API used, never a shell. |
| `composer` | `composer create-project`, the recommended route, driven from the CLI and then from the web API. |

Each run starts with **root-owned cache files**, made the way a real forum gets
them: `docker exec … php flarum cache:clear` runs as root. The checks:

- the update finishes, the package's version moves, and the site answers;
- the **after-update health check actually ran**. Inside a container the
  forum's own address is the host's, so this is the check that quietly switches
  itself off;
- **Undo this update** restores the old version, and the site answers;
- on `zip`, that `proc_open` really is disabled for the web.

## Running it

It needs Docker and about 3.5 minutes. Run it on a server rather than a laptop:
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
`127.0.0.1:18101`–`18104`. It removes all of it at the end, and leftovers from
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

Run it before releasing anything that touches how files are moved, cached or
checked.
