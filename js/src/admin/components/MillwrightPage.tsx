import app from 'flarum/admin/app';
import t from '../t';
import extractText from 'flarum/common/utils/extractText';
import apiUrl from '../apiUrl';
import { cardOffers, dismissalApplies, hidesPage, runIsLive, showingRun, sortForGrid } from '../runState';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import HostPanel from './HostPanel';
import TrashPanel from './TrashPanel';
import RunPanel from './RunPanel';
import DiscoverTab from './DiscoverTab';
import CorePanel from './CorePanel';
import SourcesTab from './SourcesTab';
import HistoryTab from './HistoryTab';

/** This extension's own package: updating it means this page's code is out of date. */
const SELF_PACKAGE = 'ernestdefoe/millwright';

declare const m: any;

interface Installed {
  id: string;
  name: string;
  package: string;
  version: string;
  icon: { backgroundColor?: string; color?: string; name?: string } | null;
  enabled: boolean;
  /** A hint from the cheap check: a newer version exists. Not a promise. */
  update: { from: string; to: string } | null;
  /** Installed from a local path, i.e. a symlink into somebody's checkout. */
  pathInstall: boolean;
}


export default class MillwrightPage extends ExtensionPage {
  /*
   * 🚨 NOT `loading`. AdminPage — which this inherits from through
   * ExtensionPage — already declares `loading`, and uses it for the Save
   * button's spinner. Shadowing it means two unrelated pieces of state share
   * one flag, and whichever writes last wins.
   */
  firstLoad = true;

  /** Set when the first load fails or never arrives, so the page can say so. */
  loadError: string | null = null;
  host: any = null;
  installed: Installed[] = [];
  updates: any = { available: {}, checkedAt: null, stale: true, uncheckable: [], tracking: [] };
  checking = false;
  tab: 'installed' | 'history' | 'discover' | 'sources' | 'host' = 'installed';
  history: any[] = [];
  run: any = null;
  driver: string | null = null;
  busy = false;
  stale = false;
  starting = false;
  notice: string | null = null;
  rollbackNote: string | null = null;
  dismissed = false;

  /**
   * 🚨 Dismissal is remembered against WHICH run, and it has to be remembered
   * at all.
   *
   * `dismissed` is component state, so it was gone on the next page load and a
   * finished run's panel came back every single time. Combined with the panel
   * hiding the tabs, that meant one update run ever — even a successful one —
   * left the page showing nothing but a week-old log, with no extension grid,
   * no Update buttons and no way to reach them. Keyed by id so a NEW run is
   * never silently pre-dismissed.
   */
  private static DISMISS_KEY = 'millwright.dismissedRun';

  private storedDismissal(): string | null {
    try {
      return localStorage.getItem(MillwrightPage.DISMISS_KEY);
    } catch {
      // Private browsing. The panel reappears, which is the safe way round.
      return null;
    }
  }

  private rememberDismissed(id: string | null) {
    if (!id) return;

    try {
      localStorage.setItem(MillwrightPage.DISMISS_KEY, id);
    } catch {
      // Private browsing: it reappears next load, which is the safe way round.
    }
  }

  oninit(vnode: any) {
    super.oninit(vnode);

    /*
     * 🚨 The safety net is armed BEFORE the thing it protects against, and the
     * first version of it was not. It sat after `this.load()`, so a load that
     * threw SYNCHRONOUSLY — which is what a bad `app.forum.attribute()` does —
     * skipped past it, escaped oninit, and left the spinner on screen forever.
     * A guard that runs only when the guarded code succeeds is not a guard.
     */
    setTimeout(() => {
      if (this.firstLoad) {
        this.loadError = t('load_timeout');
        this.firstLoad = false;
        m.redraw();
      }
    }, 10000);

    /*
     * 🚨 And the call itself cannot be allowed to escape. An exception inside a
     * Mithril lifecycle hook aborts the component's initialisation; nothing in
     * the UI changes, so the failure is invisible in exactly the place this
     * extension promises never to be.
     */
    try {
      this.load();
    } catch (e: any) {
      this.loadError = e?.message ? String(e.message) : t('load_failed');
      this.firstLoad = false;
    }
  }

  load() {
    this.loadError = null;

    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/state' })
      .then((data: any) => {
        this.host = data.host;
        this.installed = data.installed || [];
        this.history = data.history || [];
        this.updates = data.updates || this.updates;
        /*
         * 🚨 An unfinished run found on load is picked straight back up. Closing
         * the tab is not abandoning the update — the state is on disk and any
         * driver can carry it on — so reopening the page should show it running,
         * not an empty screen that invites somebody to start a second one.
         */
        this.run = data.run || null;
        this.stale = !!data.runIsStale;

        /*
         * 🚨 Restore the dismissal, but never for a run that is still going.
         * A live run's panel is the page; dismissing one must not be a way to
         * hide an update that is halfway through applying itself.
         */
        this.dismissed = dismissalApplies(this.run, this.storedDismissal());

        this.firstLoad = false;
        m.redraw();
      })
      .catch((e: any) => {
        /*
         * 🚨 The reason, on screen. Swallowing it and rendering an empty page
         * is how somebody ends up staring at a screen that looks broken with
         * nowhere to look next.
         */
        this.loadError = e?.response?.error || e?.message || t('load_failed');
        this.firstLoad = false;
        m.redraw();
      });
  }

  content() {
    if (this.firstLoad) {
      return (
        <div className="ExtensionPage-settings">
          <div className="container">
            <LoadingIndicator />
          </div>
        </div>
      );
    }

    if (this.loadError && !this.host) {
      // Nothing loaded at all. Say what happened and offer the one useful action.
      return (
        <div className="ExtensionPage-settings">
          <div className="container">
            <div className="Millwright-notice">{this.loadError}</div>
            <button
              className="Button"
              onclick={() => {
                this.firstLoad = true;
                this.load();
              }}
            >
              {t('try_again')}
            </button>
          </div>
        </div>
      );
    }

    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          {this.notice ? <div className="Millwright-notice">{this.notice}</div> : null}

          {/*
            * 🚨 While a run is live the panel is the ONLY thing on screen, and
            * the tabs go away. A grid of Update buttons beside a running update
            * invites somebody to start a second one, and the honest answer to
            * the second press is a refusal — better not to offer it.
            */}
          {this.showingRun() ? this.runPanel() : null}

          {this.hidesPage() ? null : (
          <div className="Millwright-tabs" role="tablist">
            {[
              { id: 'installed', label: t('tab_installed', { count: this.installed.length }), badge: this.updateCount() },
              { id: 'history', label: t('tab_history'), badge: 0 },
              { id: 'discover', label: t('tab_discover'), badge: 0 },
              { id: 'sources', label: t('tab_sources'), badge: 0 },
              { id: 'host', label: t('tab_host'), badge: 0 },
            ].map((tab: any) => (
              <button
                key={tab.id}
                role="tab"
                aria-selected={this.tab === tab.id}
                className={'Millwright-tab' + (this.tab === tab.id ? ' is-active' : '')}
                onclick={() => (this.tab = tab.id)}
              >
                {tab.label}
                {tab.badge > 0 ? <span className="Millwright-count">{tab.badge}</span> : null}
              </button>
            ))}
          </div>
          )}

          {this.hidesPage()
            ? null
            : this.tab === 'host'
              ? (
                <div>
                  <HostPanel host={this.host} onchange={() => this.load()} />
                  <TrashPanel />
                </div>
              )
              : this.tab === 'sources'
                ? <SourcesTab />
                : this.tab === 'history'
                ? <HistoryTab history={this.history} installed={this.installed} />
                : this.tab === 'discover'
                ? <DiscoverTab starting={this.starting} oninstall={(name: string) => this.start([name], 'install')} />
                : this.installedTab()}
        </div>
      </div>
    );
  }

  /**
   * 🚨 A FINISHED run keeps its panel until somebody dismisses it. The screen
   * returning to normal on its own would throw away the log at the exact moment
   * it becomes useful — what changed, in what order, and what to check.
   */
  showingRun(): boolean {
    return showingRun(this.run, this.dismissed);
  }

  /**
   * Whether a run is still going.
   *
   * 🚨 Only a LIVE run may take the page over. Hiding the grid while an update
   * is applying is right — a row of Update buttons beside a running update
   * invites a second one, and the honest answer to that is a refusal. Hiding it
   * because a run finished yesterday is not: the log is worth keeping on screen,
   * the rest of the page is worth having as well.
   */
  runIsLive(): boolean {
    return runIsLive(this.run);
  }

  /** The page's own content is suppressed only while something is happening. */
  private hidesPage(): boolean {
    return hidesPage(this.run, this.dismissed);
  }

  /**
   * 🚨 Millwright updating ITSELF leaves this page running the old version's
   * code: the header still said v1.15.0 under a finished v1.16.1 update
   * (ClaudiusH, 2026-10-07). So the page reloads, once per run and outcome.
   * The finished panel comes straight back, because the run is on disk.
   */
  private reloadIfSelf(run: any) {
    if (!run || !['done', 'rolled-back'].includes(run.state) || !(run.packages || []).includes(SELF_PACKAGE)) return;

    const key = 'millwright.reloaded.' + run.id + '.' + run.state;

    try {
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, '1');
    } catch (e) {
      return; // No way to remember it: better a stale page than a reload loop.
    }

    window.location.reload();
  }

  runPanel() {
    return (
      <RunPanel
        run={this.run}
        driver={this.driver}
        busy={this.busy}
        stale={this.stale}
        rollbackNote={this.rollbackNote}
        ondismiss={() => {
          this.dismissed = true;
          this.rememberDismissed(this.run?.id ?? null);
          this.rollbackNote = null;
        }}
        onprogress={(data: any) => {
          this.run = data.run;
          this.busy = !!data.busy;
          this.stale = !!data.stale;
        }}
        ondone={(run: any) => {
          this.run = run;
          // Versions on the cards are stale the moment an update lands.
          this.load();
          this.reloadIfSelf(run);
        }}
        onrollback={(data: any) => {
          this.run = data.run;
          this.rollbackNote = data.next || null;
          this.load();
          this.reloadIfSelf(data.run);
        }}
        onerror={(message: string) => (this.notice = message)}
      />
    );
  }

  /**
   * Every package with a newer version, in one press.
   *
   * 🚨 Resolved TOGETHER rather than as a queue of separate updates. Two
   * extensions can each have a newer version and still be uninstallable side by
   * side; asking Composer about all of them at once is the only way to find that
   * out before anything moves, rather than halfway through the second one.
   */
  updateAll() {
    const updatable = this.installed.filter((e) => cardOffers(e).update);
    const names = updatable.map((e) => e.package);

    if (names.length === 0) return null;

    /*
     * 🚨 If any of them is pinned, "update everything" has to ask about the
     * requirements too — otherwise it quietly skips exactly the packages the
     * admin was most deliberate about, and reports success.
     */
    const pinned = updatable.filter((e) => cardOffers(e).repin);

    return (
      <div className="Millwright-updateAll">
        <button
          className="Button Button--primary"
          disabled={this.starting}
          onclick={() => (pinned.length ? this.confirmRepin(pinned, names) : this.start(names))}
        >
          {this.starting ? t('starting') : t('update_all', { count: names.length })}
        </button>
      </div>
    );
  }

  /**
   * Raising a pin edits composer.json, so it is asked for in those words.
   *
   * 🚨 The new version is NOT sent to the server. The request carries a yes,
   * and the server takes the target from its own update check — so the only
   * version a requirement can move to is the one on the card the admin just
   * read. A version travelling from the browser into composer.json would be an
   * arbitrary string from a client.
   */
  confirmRepin(entry: any, packages?: string[]) {
    const list = Array.isArray(entry) ? entry : [entry];
    const names = packages ?? list.map((e: any) => e.package);

    const lines = list
      .map((e: any) => `  ${e.package}: ${e.constraint} → ${e.update?.to ?? '?'}`)
      .join('\n');

    if (!confirm(extractText(t('repin_confirm', { count: list.length })) + '\n\n' + lines)) return;

    this.start(names, 'update', true);
  }

  /**
   * 🚨 Confirmed, because it is the one action here that takes something away.
   * Everything else can be undone by rolling back and is described as such; a
   * removal can too, but somebody should still mean it.
   */
  confirmRemove(e: Installed) {
    if (!confirm(extractText(t('remove_confirm', { name: e.name })))) return;

    this.start([e.package], 'remove');
  }

  /**
   * @param mode 'install' adds a package that is not here yet. 🚨 It is not
   *        cosmetic: `composer update` on an uninstalled package does nothing
   *        and exits 0, so a run in the wrong mode would pass every phase,
   *        change nothing, and report success.
   */
  start(packages: string[], mode: 'update' | 'install' | 'remove' = 'update', repin = false) {
    this.starting = true;
    this.notice = null;
    this.rollbackNote = null;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: apiUrl() + '/millwright/update',
        body: { packages, mode, repin },
      })
      .then((data: any) => {
        this.starting = false;
        /*
         * 🚨 Un-dismissed only once the NEW run exists. Doing it before the
         * request re-mounted the panel on the OLD, finished run: it polled,
         * was told "idle", and stopped — in the same second the new run began.
         */
        this.dismissed = false;
        this.run = data.run;
        this.driver = data.driver || null;
        m.redraw();
      })
      .catch((e: any) => {
        this.starting = false;
        /*
         * 🚨 The server's own words. Every refusal it sends names the situation
         * — a host too small, a run already going and how long since it moved —
         * and paraphrasing that into "could not start" throws away the only
         * part anybody can act on.
         */
        const body = e?.response || {};
        this.notice = body.error || t('start_failed');
        if (body.run) {
          this.dismissed = false;
          this.run = body.run;
          this.stale = !!body.stale;
        }
        m.redraw();
      });
  }

  /**
   * The installed tab: Flarum itself, then anything to update, then the cards.
   *
   * 🚨 Every entry is keyed AND the falsy ones are removed, because Mithril
   * requires that in a fragment either every vnode has a key or none does — and
   * a `null` counts as one without. `updateAll()` returns null when nothing has
   * a newer version, so this array was legal on a forum with updates waiting and
   * a TypeError on one that was up to date. It rendered nothing at all: the
   * component threw during view, which on a Mithril page means the spinner that
   * was already on screen simply stayed there.
   *
   * Which is to say the page was broken in its most ordinary state, and one
   * load of it in a browser would have shown that immediately.
   */
  installedTab() {
    return [
      /*
       * 🚨 Above the extensions, because it is the thing whose blast radius is
       * the whole forum. It is also the one panel that disables its own button —
       * a core update with blocked extensions would be refused by Composer at
       * the end of a long wait, and knowing that in advance is the point.
       */
      <CorePanel starting={this.starting} onbegin={(pkgs: string[]) => this.start(pkgs)} />,
      this.updateAll(),
      this.checkLine(),
      this.grid(),
    ].filter(Boolean);
  }

  updateCount(): number {
    return Object.keys(this.updates?.available || {}).length;
  }

  /**
   * 🚨 The count is always shown WITH its age and its blind spots. "1 newer
   * version" is a claim; "1 newer version, checked 2 hours ago, 9 packages could
   * not be checked" is the truth, and only the second lets somebody decide
   * whether to believe it.
   */
  checkLine() {
    const n = this.updateCount();
    const uncheckable = (this.updates?.uncheckable || []).length;
    const tracking = (this.updates?.tracking || []).length;

    return (
      <div className="Millwright-checkline">
        <span>
          <b>{n === 0 ? t('none_newer') : t('some_newer', { count: n })}</b>{' '}
          {this.updates?.checkedAt ? t('checked_ago', { when: this.ago(this.updates.checkedAt) }) : t('never_checked')}
          {uncheckable > 0 ? ' ' + t('uncheckable', { count: uncheckable }) : ''}
          {tracking > 0 ? ' ' + t('tracking', { count: tracking }) : ''}
        </span>
        <button className="Button Button--link Millwright-checkNow" disabled={this.checking} onclick={() => this.checkNow()}>
          {/* A spinner as well as the words: "Checking…" alone reads as a label. */}
          {this.checking ? <i className="fas fa-circle-notch fa-spin" aria-hidden="true" /> : null}
          {this.checking ? t('checking') : t('check_now')}
        </button>
      </div>
    );
  }

  checkNow() {
    this.checking = true;
    m.redraw();

    app
      .request({ method: 'POST', url: apiUrl() + '/millwright/check' })
      .then((data: any) => {
        this.updates = data.updates || this.updates;
        this.checking = false;
        // 🚨 The cards carry their own copy of each update, and the check
        // returns only the list: a new update was counted in the line above
        // and missing from every card until a reload (ClaudiusH). Reload the
        // state the cards are built from.
        this.load();
      })
      .catch(() => {
        this.checking = false;
        m.redraw();
      });
  }

  ago(unix: number): string {
    const mins = Math.max(1, Math.round(Date.now() / 1000 - unix) / 60);
    if (mins < 60) return t('ago_minutes', { count: Math.round(mins) });
    const hours = Math.round(mins / 60);
    return hours < 48
      ? t('ago_hours', { count: hours })
      : t('ago_days', { count: Math.round(hours / 24) });
  }

  /** Anything with a newer version first — that is what somebody came to see. */
  sorted(): Installed[] {
    return sortForGrid(this.installed);
  }

  grid() {
    return (
      <div className="Millwright-grid">
        {this.sorted().map((e) => (
          <div className={'Millwright-card' + (cardOffers(e).badge ? ' Millwright-card--update' : '')} key={e.id}>
            {/* The extension's own settings page, the way the Extensions list opens it. */}
            <Link className="Millwright-cardTop" href={app.route('extension', { id: e.id })} title={extractText(t('open_settings', { name: e.name }))}>
              <div
                className="Millwright-icon"
                style={{ background: e.icon?.backgroundColor || 'var(--primary-color)' }}
              >
                {e.icon?.name ? <i className={e.icon.name} /> : e.name.charAt(0)}
              </div>
              <div className="Millwright-cardId">
                <div className="Millwright-name">{e.name}</div>
                <div className="Millwright-pkg">{e.package}</div>
              </div>

              {/*
                * 🚨 The words, at the top, where the eye lands — not only the
                * version pair in the foot.
                *
                * "1.1.0 → 1.1.1" is precise and it is not a signal: it reads as
                * metadata like every other version string on the page, so a
                * grid of thirty cards gave no way to find the one card that
                * needed attention without reading all of them.
                */}
              {cardOffers(e).badge && e.update ? (
                <span className="Millwright-badge" title={e.update.from + ' → ' + e.update.to}>
                  {t('update_available')}
                </span>
              ) : null}
            </Link>

            <div className="Millwright-meta">
              <span>{e.version || t('version_unknown')}</span>
            </div>

            <div className="Millwright-foot">
              {/*
                * 🚨 Always two groups: what is TRUE about this extension on the
                * left, what you can DO with it on the right. The foot is
                * space-between, so loose chips get pushed to opposite ends of
                * the card and read as unrelated — which is what "enabled" and
                * "local checkout" did.
                */}
              <span className="Millwright-tags">
                {e.update ? (
                  <span className="Millwright-tag Millwright-tag--warn">
                    {e.update.from} → {e.update.to}
                  </span>
                ) : (
                  <span className={'Millwright-tag' + (e.enabled ? ' Millwright-tag--ok' : '')}>
                    {e.enabled ? t('enabled') : t('disabled')}
                  </span>
                )}
                {e.pathInstall ? (
                  <span className="Millwright-tag Millwright-tag--muted" title={t('path_install_why')}>
                    {t('path_install')}
                  </span>
                ) : null}
              </span>

              <span className="Millwright-actions">
                {cardOffers(e).update ? (
                  <button
                    className="Button Button--primary Button--sm"
                    disabled={this.starting}
                    onclick={() => (cardOffers(e).repin ? this.confirmRepin(e) : this.start([e.package]))}
                  >
                    {t('update')}
                  </button>
                ) : null}
                {cardOffers(e).remove ? (
                  <button className="Button Button--sm" disabled={this.starting} onclick={() => this.confirmRemove(e)}>
                    {t('remove')}
                  </button>
                ) : null}
              </span>
            </div>
          </div>
        ))}
      </div>
    );
  }
}
