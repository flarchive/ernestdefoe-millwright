import app from 'flarum/admin/app';
import apiUrl from '../apiUrl';
import { liveSearchPlan, submittedSearchPlan } from '../searchPolicy';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

interface Found {
  name: string;
  description: string;
  downloads: number;
  favers: number;
  repository: string;
  abandoned: boolean | string;
  installed: boolean;
}

interface Verdict {
  compatible: boolean | null;
  version: string | null;
  requires: string | null;
  stability: string | null;
}

interface DiscoverAttrs {
  starting: boolean;
  oninstall: (packageName: string) => void;
}

/**
 * Finding something to install.
 *
 * 🚨 The one thing this does that the current tooling does not: it says whether
 * a result works with the Flarum you actually have, BEFORE you press anything.
 * Extension Manager lists all of Packagist and lets you discover at install time
 * that an extension is Flarum 1 only — after the resolve, after the wait, and in
 * the form of a constraint error rather than a sentence.
 *
 * 🚨 Verdicts arrive in a second request, so results appear immediately and the
 * badges fill in. Waiting on a dozen round trips before showing anything would
 * be the slower, worse version of the same screen.
 */
export default class DiscoverTab extends Component<DiscoverAttrs> {
  /*
   * 🚨 The list loads on open, with no query. Extension Manager shows everything
   * installable straight away, and that is the right behaviour: somebody opening
   * a tab called "Find extensions" is asking what exists, not to guess a search
   * term first. The first version required typing before anything appeared,
   * which made a catalogue behave like a command line.
   *
   * Packagist orders an untyped `type=flarum-extension` query by popularity, so
   * the default view is the extensions most forums actually run.
   */
  oninit(vnode: any) {
    super.oninit(vnode);
    this.search();
  }

  private query = '';
  private searching = false;
  private searched = false;
  private page = 1;
  private more = false;
  private loadingMore = false;
  private results: Found[] = [];
  private verdicts: Record<string, Verdict> = {};
  private checking = false;
  private error: string | null = null;
  private core: string | null = null;
  private seq = 0;
  /** The query whose results are on screen, so a keystroke can tell if anything changed. */
  private lastSearched: string | null = null;
  /** The pending debounce, so a new keystroke replaces it rather than adding to it. */
  private typing: ReturnType<typeof setTimeout> | null = null;

  view() {
    return (
      <div className="Millwright-discover">
        <form
          className="Millwright-search"
          onsubmit={(e: Event) => {
            e.preventDefault();

            /*
             * 🚨 Cancel the pending keystroke search first, or Return fires one
             * search immediately and a second one 350ms later for the same
             * query — two round trips and a list that redraws under the reader.
             */
            this.stopTyping();

            if (submittedSearchPlan().search) this.search();
          }}
        >
          <input
            className="FormControl"
            type="search"
            placeholder={t('discover_placeholder') as unknown as string}
            value={this.query}
            oninput={(e: any) => {
              this.query = e.target.value;
              this.queueSearch();
            }}
          />
          {/*
            * Kept, and not decorative: it forces a search the typing rule
            * declines — a one-character vendor prefix, or the same query again
            * after an error.
            */}
          <button className="Button Button--primary" type="submit" disabled={this.searching}>
            {this.searching ? t('searching') : t('search')}
          </button>
        </form>

        <div className="Millwright-searchNote">
          {this.core ? t('discover_against', { version: this.core }) : t('discover_hint')}
        </div>

        {this.error ? <div className="Millwright-notice">{this.error}</div> : null}

        {this.searching ? <LoadingIndicator /> : null}

        {!this.searching && this.searched && this.results.length === 0 && !this.error ? (
          <div className="Millwright-empty">
            {this.query ? t('discover_none', { query: this.query }) : t('discover_empty')}
          </div>
        ) : null}

        <div className="Millwright-grid">{this.results.map((r) => this.card(r))}</div>

        {this.more && !this.searching ? (
          <div className="Millwright-more">
            <button className="Button" disabled={this.loadingMore} onclick={() => this.showMore()}>
              {this.loadingMore ? t('loading_more') : t('show_more')}
            </button>
          </div>
        ) : null}
      </div>
    );
  }

  /**
   * A keystroke, considered.
   *
   * The rule lives in searchPolicy so it can be tested; this is only the timer.
   */
  queueSearch() {
    const plan = liveSearchPlan(this.query, this.lastSearched);

    this.stopTyping();

    if (! plan.search) return;

    this.typing = setTimeout(() => {
      this.typing = null;
      this.search();
    }, plan.delayMs);
  }

  stopTyping() {
    if (this.typing !== null) {
      clearTimeout(this.typing);
      this.typing = null;
    }
  }

  /*
   * 🚨 A timer that outlives its component fires into a dead tab: it redraws a
   * page that has moved on, and on a slow search it can overwrite the results
   * of whatever the reader opened next.
   */
  onremove() {
    this.stopTyping();
  }

  card(r: Found) {
    const v = this.verdicts[r.name];
    const [vendor, short] = r.name.split('/');

    return (
      <div className="Millwright-card" key={r.name}>
        <div className="Millwright-cardTop">
          <div className="Millwright-icon" style={{ background: 'var(--control-bg)', color: 'var(--muted-color)' }}>
            {(short || vendor || '?').charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="Millwright-name">{short}</div>
            <div className="Millwright-pkg">{r.name}</div>
          </div>
        </div>

        <div className="Millwright-desc">{r.description}</div>

        <div className="Millwright-meta">
          <span>{t('downloads', { count: this.short(r.downloads) })}</span>
        </div>

        <div className="Millwright-foot">
          <span className="Millwright-tags">
            {/*
              * 🚨 Abandoned and "does it work" are separate facts and both are
              * shown. Putting abandoned in the single badge slot hid the
              * compatibility answer while still offering Install, so the tag
              * beside the button read like a verdict on the wrong question.
              */}
            {r.abandoned ? (
              <span className="Millwright-tag Millwright-tag--warn" title={this.abandonedTitle(r)}>
                {t('abandoned')}
              </span>
            ) : null}
            {this.badge(v)}
          </span>
          {this.action(r, v)}
        </div>
      </div>
    );
  }

  /**
   * 🚨 Four states, and "we don't know yet" is one of them. Collapsing unknown
   * into "no" hides working extensions; collapsing it into "yes" offers ones
   * that cannot install. Either way somebody stops believing the badge.
   */
  badge(v?: Verdict) {
    if (!v) {
      return <span className="Millwright-tag Millwright-tag--muted">{this.checking ? t('checking') : '—'}</span>;
    }

    if (v.compatible === null) {
      return <span className="Millwright-tag Millwright-tag--muted">{t('compat_unknown')}</span>;
    }

    if (!v.compatible) {
      return (
        <span className="Millwright-tag Millwright-tag--muted" title={t('compat_no_why', { requires: v.requires || '?' }) as unknown as string}>
          {t('compat_no')}
        </span>
      );
    }

    // 🚨 A pre-release is labelled as one. Most forums are on stable minimum
    // stability and cannot install it at all, and somebody who can should still
    // be told that is what they are getting.
    return v.stability && v.stability !== 'stable' ? (
      <span className="Millwright-tag Millwright-tag--warn">{t('compat_prerelease', { version: v.version })}</span>
    ) : (
      <span className="Millwright-tag Millwright-tag--ok">{v.version}</span>
    );
  }

  /**
   * Packagist records a replacement package when the author names one, and that
   * is the single most useful thing to say about an abandoned extension.
   */
  abandonedTitle(r: Found): string {
    return typeof r.abandoned === 'string' && r.abandoned
      ? (t('abandoned_for', { replacement: r.abandoned }) as unknown as string)
      : (t('abandoned_why') as unknown as string);
  }

  action(r: Found, v?: Verdict) {
    if (r.installed) {
      return <span className="Millwright-tag Millwright-tag--ok">{t('already_installed')}</span>;
    }

    // Not offered when it cannot work. The badge beside it says why.
    if (!v || v.compatible !== true) return null;

    return (
      <button
        className="Button Button--primary Button--sm"
        disabled={this.attrs.starting}
        onclick={() => this.attrs.oninstall(r.name)}
      >
        {t('install')}
      </button>
    );
  }

  short(n: number): string {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return Math.round(n / 1000) + 'k';
    return String(n);
  }

  search() {
    const q = this.query.trim();

    /*
     * 🚨 Every search carries a number, and a reply is dropped unless it is the
     * newest. Typing fast means several searches in flight, and without this the
     * slowest one wins — the results end up belonging to a query the person
     * already moved on from.
     */
    const mine = ++this.seq;

    /*
     * Recorded when the search STARTS, not when it returns: it is what the
     * typing rule compares against, and between here and the reply every
     * keystroke that types the same string back would otherwise queue another
     * identical request.
     */
    this.lastSearched = q;

    this.page = 1;
    this.searching = true;
    this.searched = true;
    this.error = null;
    m.redraw();

    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/discover?q=' + encodeURIComponent(q) })
      .then((data: any) => {
        if (mine !== this.seq) return;

        this.searching = false;
        this.results = data.results || [];
        this.more = !!data.more;
        this.error = data.error || null;
        m.redraw();

        if (this.results.length) this.checkCompat(this.results.map((r) => r.name), mine);
      })
      .catch(() => {
        if (mine !== this.seq) return;
        this.searching = false;
        /*
         * 🚨 Forget the query on failure, so the same one can be tried again.
         * Otherwise a network blip leaves the box holding a query the typing
         * rule now considers already-shown, and nothing the reader types —
         * short of changing it — will retry.
         */
        this.lastSearched = null;
        this.error = t('discover_failed') as unknown as string;
        m.redraw();
      });
  }

  /**
   * The next page, appended.
   *
   * 🚨 Appended rather than replacing, because browsing is a scroll and losing
   * what you already looked at to see more of it is the wrong trade. Each page
   * costs its own compatibility pass, which is why they are fetched a page at a
   * time rather than all 2306 at once.
   */
  showMore() {
    const mine = this.seq;
    this.loadingMore = true;
    m.redraw();

    app
      .request({
        method: 'GET',
        url: apiUrl() + '/millwright/discover?page=' + (this.page + 1) + '&q=' + encodeURIComponent(this.query.trim()),
      })
      .then((data: any) => {
        if (mine !== this.seq) return;

        this.page += 1;
        this.loadingMore = false;
        this.more = !!data.more;

        const fresh: Found[] = data.results || [];
        this.results = this.results.concat(fresh);
        m.redraw();

        if (fresh.length) this.checkCompat(fresh.map((r) => r.name), mine);
      })
      .catch(() => {
        if (mine !== this.seq) return;
        this.loadingMore = false;
        this.more = false;
        m.redraw();
      });
  }

  checkCompat(names: string[], mine: number) {
    this.checking = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: apiUrl() + '/millwright/discover/compat',
        body: { packages: names },
      })
      .then((data: any) => {
        if (mine !== this.seq) return;
        this.checking = false;
        this.verdicts = { ...this.verdicts, ...(data.verdicts || {}) };
        this.core = data.core || this.core;
        m.redraw();
      })
      .catch(() => {
        if (mine !== this.seq) return;
        // 🚨 The results stay. Failing to work out compatibility is not a
        // reason to throw away a search that worked — the badges just stay
        // unknown, which is what they honestly are.
        this.checking = false;
        m.redraw();
      });
  }
}
