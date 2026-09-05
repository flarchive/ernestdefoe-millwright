import app from 'flarum/admin/app';
import apiUrl from '../apiUrl';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import HostPanel from './HostPanel';
import RunPanel from './RunPanel';
import DiscoverTab from './DiscoverTab';
import CorePanel from './CorePanel';

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

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

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
  updates: any = { available: {}, checkedAt: null, stale: true, uncheckable: [] };
  checking = false;
  tab: 'installed' | 'discover' | 'host' = 'installed';
  run: any = null;
  driver: string | null = null;
  busy = false;
  stale = false;
  starting = false;
  notice: string | null = null;
  rollbackNote: string | null = null;
  dismissed = false;

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
        this.loadError = t('load_timeout') as unknown as string;
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
      this.loadError = e?.message ? String(e.message) : (t('load_failed') as unknown as string);
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
        this.updates = data.updates || this.updates;
        /*
         * 🚨 An unfinished run found on load is picked straight back up. Closing
         * the tab is not abandoning the update — the state is on disk and any
         * driver can carry it on — so reopening the page should show it running,
         * not an empty screen that invites somebody to start a second one.
         */
        this.run = data.run || null;
        this.stale = !!data.runIsStale;
        this.firstLoad = false;
        m.redraw();
      })
      .catch((e: any) => {
        /*
         * 🚨 The reason, on screen. Swallowing it and rendering an empty page
         * is how somebody ends up staring at a screen that looks broken with
         * nowhere to look next.
         */
        this.loadError = e?.response?.error || e?.message || (t('load_failed') as unknown as string);
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

          {this.showingRun() ? null : (
          <div className="ButtonGroup" style={{ marginBottom: '20px' }}>
            <button
              className={'Button' + (this.tab === 'installed' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'installed')}
            >
              {t('tab_installed', { count: this.installed.length })}
              {this.updateCount() > 0 ? (
                <span className="Millwright-count">{this.updateCount()}</span>
              ) : null}
            </button>
            <button
              className={'Button' + (this.tab === 'discover' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'discover')}
            >
              {t('tab_discover')}
            </button>
            <button
              className={'Button' + (this.tab === 'host' ? ' Button--primary' : '')}
              onclick={() => (this.tab = 'host')}
            >
              {t('tab_host')}
            </button>
          </div>
          )}

          {this.showingRun()
            ? null
            : this.tab === 'host'
              ? <HostPanel host={this.host} />
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
    return !!this.run && !this.dismissed;
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
        }}
        onrollback={(data: any) => {
          this.run = data.run;
          this.rollbackNote = data.next || null;
          this.load();
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
    const names = this.installed.filter((e) => e.update && !e.pathInstall).map((e) => e.package);

    if (names.length === 0) return null;

    return (
      <div className="Millwright-updateAll" key="updateall">
        <button className="Button Button--primary" disabled={this.starting} onclick={() => this.start(names)}>
          {this.starting ? t('starting') : t('update_all', { count: names.length })}
        </button>
      </div>
    );
  }

  /**
   * 🚨 Confirmed, because it is the one action here that takes something away.
   * Everything else can be undone by rolling back and is described as such; a
   * removal can too, but somebody should still mean it.
   */
  confirmRemove(e: Installed) {
    if (!confirm(t('remove_confirm', { name: e.name }) as unknown as string)) return;

    this.start([e.package], 'remove');
  }

  /**
   * @param mode 'install' adds a package that is not here yet. 🚨 It is not
   *        cosmetic: `composer update` on an uninstalled package does nothing
   *        and exits 0, so a run in the wrong mode would pass every phase,
   *        change nothing, and report success.
   */
  start(packages: string[], mode: 'update' | 'install' | 'remove' = 'update') {
    this.starting = true;
    this.notice = null;
    this.rollbackNote = null;
    this.dismissed = false;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: apiUrl() + '/millwright/update',
        body: { packages, mode },
      })
      .then((data: any) => {
        this.starting = false;
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
      <CorePanel key="core" starting={this.starting} onbegin={(pkgs: string[]) => this.start(pkgs)} />,
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

    return (
      <div className="Millwright-checkline" key="checkline">
        <span>
          <b>{n === 0 ? t('none_newer') : t('some_newer', { count: n })}</b>{' '}
          {this.updates?.checkedAt ? t('checked_ago', { when: this.ago(this.updates.checkedAt) }) : t('never_checked')}
          {uncheckable > 0 ? ' ' + t('uncheckable', { count: uncheckable }) : ''}
        </span>
        <button className="Button Button--link" disabled={this.checking} onclick={() => this.checkNow()}>
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
        this.installed = data.installed || this.installed;
        this.checking = false;
        m.redraw();
      })
      .catch(() => {
        this.checking = false;
        m.redraw();
      });
  }

  ago(unix: number): string {
    const mins = Math.max(1, Math.round(Date.now() / 1000 - unix) / 60);
    if (mins < 60) return t('ago_minutes', { count: Math.round(mins) }) as unknown as string;
    const hours = Math.round(mins / 60);
    return hours < 48
      ? (t('ago_hours', { count: hours }) as unknown as string)
      : (t('ago_days', { count: Math.round(hours / 24) }) as unknown as string);
  }

  /** Anything with a newer version first — that is what somebody came to see. */
  sorted(): Installed[] {
    return [...this.installed].sort((a, b) => Number(!!b.update) - Number(!!a.update));
  }

  grid() {
    return (
      <div className="Millwright-grid" key="grid">
        {this.sorted().map((e) => (
          <div className="Millwright-card" key={e.id}>
            <div className="Millwright-cardTop">
              <div
                className="Millwright-icon"
                style={{ background: e.icon?.backgroundColor || 'var(--primary-color)' }}
              >
                {e.icon?.name ? <i className={e.icon.name} /> : e.name.charAt(0)}
              </div>
              <div>
                <div className="Millwright-name">{e.name}</div>
                <div className="Millwright-pkg">{e.package}</div>
              </div>
            </div>

            <div className="Millwright-meta">
              <span>{e.version || t('version_unknown')}</span>
            </div>

            <div className="Millwright-foot">
              {e.update ? (
                <span className="Millwright-tag Millwright-tag--warn">
                  {e.update.from} → {e.update.to}
                </span>
              ) : (
                <span className={'Millwright-tag' + (e.enabled ? ' Millwright-tag--ok' : '')}>
                  {e.enabled ? t('enabled') : t('disabled')}
                </span>
              )}

              {/*
                * 🚨 A path install is explained here rather than refused later.
                * The extension is a symlink into a checkout on this machine, so
                * replacing it would leave the forum running a downloaded copy
                * while its owner carries on editing a directory nothing reads.
                */}
              {e.pathInstall ? (
                <span className="Millwright-tag Millwright-tag--muted" title={t('path_install_why') as unknown as string}>
                  {t('path_install')}
                </span>
              ) : (
                <span className="Millwright-actions">
                  {e.update ? (
                    <button className="Button Button--primary Button--sm" disabled={this.starting} onclick={() => this.start([e.package])}>
                      {t('update')}
                    </button>
                  ) : null}
                  {/*
                    * 🚨 Offered only once the extension is switched off. Taking
                    * the files away from an extension that is still enabled
                    * leaves Flarum with something it cannot load, and disabling
                    * first is one click on a page they already know.
                    */}
                  {e.enabled ? null : (
                    <button
                      className="Button Button--sm"
                      disabled={this.starting}
                      onclick={() => this.confirmRemove(e)}
                    >
                      {t('remove')}
                    </button>
                  )}
                </span>
              )}
            </div>
          </div>
        ))}
      </div>
    );
  }
}
