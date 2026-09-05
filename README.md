# Millwright

Update, install and remove Flarum extensions — and Flarum itself — on any host,
including shared hosting.

**Nothing is deleted, and every step is written down before it is taken.** An
interrupted update costs you progress, never your site.

---

## Requirements

- Flarum **2.0** or newer
- PHP **8.3+**
- PHP must be allowed to start a subprocess (`proc_open`). Millwright tells you
  on its own settings page if your host forbids this.

Composer ships with Millwright, so your host does not need it installed.

## Install

```bash
composer require ernestdefoe/millwright
```

Then enable it from **Administration → Extensions**.

## What it does

**Updates extensions.** One at a time, or everything with a newer version in one
resolve — which matters, because two extensions can each have an update and
still be uninstallable side by side. Asking about them together is the only way
to find that out before anything moves.

**Installs new ones.** Search Packagist from the admin page, and every result
says whether it has a release that works with **the Flarum you are actually
running** — before you press anything, rather than as a constraint error at the
end of a long wait.

**Removes them.** The same operation as any other, so the files go to the trash
rather than being deleted, and an uninstall is as reversible as an update. Your
settings and any data the extension stored are left alone: reinstalling puts you
back exactly where you were.

**Updates Flarum itself** — and first tells you, per extension and by name, which
of yours have a release that works with the version you are moving to. Anything
that would block the upgrade is listed at the top, and the button stays disabled
until it is resolved. Extensions that need to move are updated in the same
resolve as core, because updating core alone leaves Composer refusing.

**Tells you what your host will allow, before you press anything.** Memory,
execution limit, whether Composer can be run at all, whether PHP will even notice
the new files, and how much disk is free — each with what it *means*, not just
what it is.

**Checks for newer versions** on a schedule, and puts a count on the dashboard.
The wording is deliberate: it says a newer version *exists*, never that an update
*is available*. Only a real resolve knows the second, and a badge that overstates
itself is one people learn to ignore.

## How it works

### Applying a change is two renames, never a delete

```
vendor/<pkg>   →  trash/<pkg>@<version>     the old one is kept
staging/<pkg>  →  vendor/<pkg>              the new one arrives
```

Both are `rename()`, so each is atomic. The package is absent between them and
only between them — microseconds, for one package. Nothing is deleted during an
apply at all: the old version waits in the trash, which is what makes rollback
possible long after the fact.

### The journal is written before the act, and flushed to disk

A crash therefore leaves evidence of an *intention*, which is recoverable. A
journal written afterwards would leave a changed tree with no record of what
changed, which is not. Rollback replays it backwards, and its cost is
proportional to what changed rather than to the size of your `vendor/` — so it
works on a host with a disk quota where copying the tree would not.

### Nothing loops

One request does exactly one unit of work and returns. Progress is a function of
how many times something calls the step endpoint — the admin page polling, a cron
tick, or a queue worker, interchangeably, and any of them can pick up a run
another started. A host that cuts every request at thirty seconds can therefore
finish an update that takes ten minutes, which no amount of making the update
faster would have achieved.

If you have a queue worker, it carries the work on when you close the tab. If you
do not, the page does it. **The queue is never what makes an update work** — that
distinction is the whole design.

## What it deliberately does not do

**It will not touch an extension installed from a local path.** If
`vendor/you/thing` is a symlink into a checkout on the server — how extension
developers work — replacing it would leave your forum running a downloaded copy
while you carry on editing a directory nothing reads. Those are labelled *local
checkout* and offered no buttons.

**It does not flip symlinks between prepared slots.** This was planned, and
measurement killed it: `opcache.revalidate_path` is off by default, so PHP
resolves a symlink once and caches the result, and `realpath_cache_ttl` holds the
old target for two minutes more. A slot flip is atomic on disk and invisible to
PHP — it would trade a microsecond where a package is missing for minutes of
quietly serving the old code. Replacing a directory keeps the path constant, so
the ordinary timestamp check picks it up in seconds.

**It does not delete your data when you remove an extension.** Tables and
settings stay. This matches Extension Manager, and it is the reversible choice.

## Why it exists

Flarum's Extension Manager fails in a way that can take a site down, and the
failure is hard to diagnose from the admin screen.

This is not a criticism of the people who wrote it. It is an older design that
predates Flarum 2, and the specific code has not been changed upstream since
2024. A fix for the worst of it has been offered back —
[flarum/framework#5034](https://github.com/flarum/framework/pull/5034) — but the
rest cannot be fixed without changing the shape of the thing, which is why this
is a separate extension rather than a patch.

The mechanism, specifically: it runs Composer inside the PHP worker serving the
request, caps memory from inside that process, and swaps the vendor directory by
**deleting it and moving a new one over the top** — leaving the forum with no
`vendor/` at all for as long as that takes. If the job is killed inside that
window the site stops booting; the task row still says `running`, and every later
update is silently refused because something looks busy.

Millwright is built the other way round.

## Tests

```bash
composer install && vendor/bin/phpunit
```

The suite that matters is `CrashRecoveryTest`. It runs a real apply in a
subprocess and **SIGKILLs it at every step in the sequence** — before anything is
touched, after the old version is stashed but before the new one lands, after it
lands but before the journal records it, and after the record is complete — then
rolls back and asserts the tree is byte-for-byte what it was.

A thrown exception would be a weaker test: it unwinds the stack and runs
destructors, which a host killing an overrunning request does not.

`ResumabilityTest` goes further and kills at *random* points, repeatedly, until a
run completes — a better model of a real host, where the cut lands wherever the
clock happens to be. One case kills in the single gap that matters: between doing
an item and recording it. That item is then redone on resume, and the test
asserts the repeat rather than pretending it cannot happen. Everything in the
applier is built so a repeat is a no-op.

## Licence

MIT. See [LICENSE](LICENSE).
