import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import apiUrl from '../apiUrl';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

/**
 * Where Composer looks, what it will accept, and how it authenticates.
 *
 * 🚨 These three settings are why the extension this replaces could not simply
 * be removed. Millwright could install and update, but it could not tell
 * Composer where a private repository lives, that release candidates are
 * acceptable, or how to authenticate to a paid one — and a forum whose own
 * extensions live in private repositories cannot resolve a single one without
 * them.
 *
 * 🚨 A stored credential is never sent to the browser. This screen shows which
 * hosts have one; entering a value replaces it. That is a deliberate limit, not
 * an oversight — a settings page that displays your token back to you turns
 * every screenshot into a leak.
 */
export default class SourcesTab extends Component {
  private loading = true;
  private saving = false;
  private data: any = null;
  private error: string | null = null;

  // add-repository form
  private repoType = 'vcs';
  private repoUrl = '';

  // add-credential form
  private authKind = 'github-oauth';
  private authHost = '';
  private authUser = '';
  private authSecret = '';

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.data) return <div className="Millwright-notice">{this.error || t('config_failed')}</div>;

    return (
      <div className="Millwright-sources">
        {this.error ? <div className="Millwright-notice">{this.error}</div> : null}
        <div className="Millwright-sourcesNote">{this.data.note}</div>

        {this.repositories()}
        {this.stability()}
        {this.credentials()}
      </div>
    );
  }

  // ── repositories ────────────────────────────────────────────────────────

  repositories() {
    const repos = this.data.repositories || [];

    return (
      <section className="Millwright-card">
        <header className="Millwright-cardHead">
          <h3>{t('repos_title')}</h3>
          <p>{t('repos_help')}</p>
        </header>

        {repos.length === 0 ? (
          <div className="Millwright-cardEmpty">{t('repos_none')}</div>
        ) : (
          <ul className="Millwright-rows">
            {repos.map((r: any) => (
              <li className="Millwright-row" key={r.url}>
                <span className="Millwright-kind">{r.type}</span>
                <span className="Millwright-rowMain" title={r.url}>{r.url}</span>
                <span />
                <button
                  className="Millwright-rowAction"
                  disabled={this.saving}
                  title={t('remove') as unknown as string}
                  onclick={() => this.removeRepo(r)}
                >
                  {t('remove')}
                </button>
              </li>
            ))}
          </ul>
        )}

        <form
          className="Millwright-cardFoot"
          onsubmit={(e: Event) => {
            e.preventDefault();
            this.send({ action: 'add-repository', type: this.repoType, url: this.repoUrl }, () => (this.repoUrl = ''));
          }}
        >
          <select className="FormControl Millwright-kindSelect" value={this.repoType} onchange={(e: any) => (this.repoType = e.target.value)}>
            {(this.data.types || []).map((ty: string) => (
              <option key={ty} value={ty}>{ty}</option>
            ))}
          </select>
          <input
            className="FormControl"
            placeholder={t('repos_placeholder') as unknown as string}
            value={this.repoUrl}
            oninput={(e: any) => (this.repoUrl = e.target.value)}
          />
          <button className="Button Button--primary" type="submit" disabled={this.saving || !this.repoUrl.trim()}>
            {t('add')}
          </button>
        </form>
      </section>
    );
  }

  removeRepo(r: any) {
    if (!confirm(t('repos_confirm', { url: r.url }) as unknown as string)) return;

    this.send({ action: 'remove-repository', url: r.url });
  }

  // ── stability ───────────────────────────────────────────────────────────

  stability() {
    const s = this.data.stability || {};

    return (
      <section className="Millwright-card">
        <header className="Millwright-cardHead">
          <h3>{t('stability_title')}</h3>
          <p>{t('stability_help')}</p>
        </header>

        <div className="Millwright-cardControls">
          <select
            className="FormControl"
            value={s.minimumStability}
            disabled={this.saving}
            onchange={(e: any) =>
              this.send({ action: 'set-stability', minimumStability: e.target.value, preferStable: s.preferStable })
            }
          >
            {(s.levels || []).map((l: string) => (
              <option key={l} value={l}>{l}</option>
            ))}
          </select>

          <label className="Millwright-check">
            <input
              type="checkbox"
              checked={s.preferStable}
              disabled={this.saving}
              onchange={(e: any) =>
                this.send({ action: 'set-stability', minimumStability: s.minimumStability, preferStable: e.target.checked })
              }
            />
            {t('prefer_stable')}
          </label>
        </div>

        {/*
          * 🚨 The consequence of the CURRENT setting, in words. "beta" is a
          * value; "most Flarum 2 extensions are published as betas, so this is
          * the usual choice" is the thing somebody is actually deciding about.
          */}
        <div className="Millwright-callout">{s.consequence}</div>
      </section>
    );
  }

  // ── credentials ─────────────────────────────────────────────────────────

  credentials() {
    const a = this.data.auth || {};
    const stored = a.stored || [];

    return (
      <section className="Millwright-card">
        <header className="Millwright-cardHead">
          <h3>{t('auth_title')}</h3>
          <p>{t('auth_help')}</p>
        </header>

        {stored.length === 0 ? (
          <div className="Millwright-cardEmpty">{t('auth_none')}</div>
        ) : (
          <ul className="Millwright-rows">
            {stored.map((c: any) => (
              <li className="Millwright-row" key={c.kind + c.host}>
                <span className="Millwright-kind">{c.kind}</span>
                <span className="Millwright-rowMain">{c.host}</span>
                <span className="Millwright-held">{c.detail}</span>
                <button
                  className="Millwright-rowAction"
                  disabled={this.saving}
                  onclick={() => this.removeAuth(c)}
                >
                  {t('remove')}
                </button>
              </li>
            ))}
          </ul>
        )}

        <form
          className="Millwright-cardFoot Millwright-cardFoot--wrap"
          onsubmit={(e: Event) => {
            e.preventDefault();
            this.send(
              {
                action: 'set-auth',
                kind: this.authKind,
                host: this.authHost,
                username: this.authUser,
                secret: this.authSecret,
              },
              () => {
                // 🚨 Cleared from memory as soon as it has been sent.
                this.authSecret = '';
                this.authUser = '';
                this.authHost = '';
              }
            );
          }}
        >
          <select className="FormControl Millwright-kindSelect" value={this.authKind} onchange={(e: any) => (this.authKind = e.target.value)}>
            {(a.kinds || []).map((k: string) => (
              <option key={k} value={k}>{k}</option>
            ))}
          </select>
          <input
            className="FormControl"
            placeholder={t('auth_host_placeholder') as unknown as string}
            value={this.authHost}
            oninput={(e: any) => (this.authHost = e.target.value)}
          />
          {this.authKind === 'http-basic' ? (
            <input
              className="FormControl"
              placeholder={t('auth_user_placeholder') as unknown as string}
              value={this.authUser}
              oninput={(e: any) => (this.authUser = e.target.value)}
            />
          ) : null}
          <input
            className="FormControl"
            type="password"
            autocomplete="off"
            placeholder={t('auth_secret_placeholder') as unknown as string}
            value={this.authSecret}
            oninput={(e: any) => (this.authSecret = e.target.value)}
          />
          <button className="Button Button--primary" type="submit" disabled={this.saving || !this.authHost.trim() || !this.authSecret}>
            {t('save')}
          </button>
        </form>

        <div className="Millwright-callout Millwright-callout--lock">{t('auth_writeonly')}</div>
      </section>
    );
  }

  removeAuth(c: any) {
    if (!confirm(t('auth_confirm', { host: c.host }) as unknown as string)) return;

    this.send({ action: 'remove-auth', kind: c.kind, host: c.host });
  }

  // ── plumbing ────────────────────────────────────────────────────────────

  load() {
    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/config' })
      .then((data: any) => {
        this.data = data;
        this.loading = false;
        m.redraw();
      })
      .catch((e: any) => {
        this.loading = false;
        this.error = e?.response?.error || (t('config_failed') as unknown as string);
        m.redraw();
      });
  }

  send(body: any, after?: () => void) {
    this.saving = true;
    this.error = null;
    m.redraw();

    app
      .request({ method: 'POST', url: apiUrl() + '/millwright/config', body })
      .then((data: any) => {
        this.data = data;
        this.saving = false;
        if (after) after();
        m.redraw();
      })
      .catch((e: any) => {
        /*
         * 🚨 The server's own words. Every refusal from the Config classes is
         * written for the person who typed the value — "that is not a hostname"
         * tells them what to change; "could not save" does not.
         */
        this.saving = false;
        this.error = e?.response?.error || (t('config_failed') as unknown as string);
        m.redraw();
      });
  }
}
