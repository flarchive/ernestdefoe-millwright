import app from 'flarum/admin/app';
import apiUrl from '../apiUrl';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

/**
 * Tells an admin, on the page they already land on, that something is out of
 * date — without overstating what that means.
 *
 * 🚨 The wording matters more than the banner. "3 updates available" is a claim
 * this cannot back: the cheap Packagist check knows a newer version EXISTS, not
 * that it will install alongside everything else on the forum. So it says a
 * newer version exists and offers to go and find out properly, which is a
 * sentence that stays true.
 *
 * 🚨 It also carries its own age. A badge with no timestamp is indistinguishable
 * from a stale one, and a stale badge is how people learn to ignore badges.
 */
export default class UpdateBanner extends Component {
  loading = true;
  count = 0;
  checkedAt: number | null = null;
  stale = false;
  uncheckable = 0;
  dismissed = false;

  oninit(vnode: any) {
    super.oninit(vnode);

    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/state' })
      .then((data: any) => {
        const updates = data.updates || {};
        this.count = Object.keys(updates.available || {}).length;
        this.checkedAt = updates.checkedAt ?? null;
        this.stale = !!updates.stale;
        this.uncheckable = (updates.uncheckable || []).length;
        this.dismissed = this.wasDismissed(Object.keys(updates.available || {}));
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        // A dashboard is not the place to report that a background check could
        // not run. The Millwright page says so properly.
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading || this.count === 0 || this.dismissed) return null;

    return (
      <div className="Millwright-banner">
        <div>
          <div className="Millwright-banner-title">{t('banner_title', { count: this.count })}</div>
          <div className="Millwright-banner-body">
            {t('banner_body')}
            {this.checkedAt ? ' ' + t('banner_checked', { when: this.ago(this.checkedAt) }) : ''}
            {this.uncheckable > 0 ? ' ' + t('banner_uncheckable', { count: this.uncheckable }) : ''}
          </div>
        </div>
        <div className="Millwright-banner-actions">
          {LinkButton.component(
            { className: 'Button Button--primary', href: app.route('extension', { id: 'ernestdefoe-millwright' }) },
            t('banner_open')
          )}
          {Button.component({ className: 'Button Button--link', onclick: () => this.dismiss() }, t('banner_dismiss'))}
        </div>
      </div>
    );
  }

  /**
   * 🚨 Dismissal is remembered against WHICH packages were out of date, not as a
   * flag. Dismissing "3 updates" and then never hearing about a fourth is how a
   * notification quietly stops working.
   */
  private key(packages: string[]): string {
    return 'millwright.dismissed.' + packages.sort().join(',');
  }

  private wasDismissed(packages: string[]): boolean {
    try {
      return localStorage.getItem(this.key(packages)) === '1';
    } catch {
      return false;
    }
  }

  private dismiss() {
    this.dismissed = true;

    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/state' })
      .then((data: any) => {
        try {
          localStorage.setItem(this.key(Object.keys(data.updates?.available || {})), '1');
        } catch {
          // Private browsing. It reappears next load, which is the safe way round.
        }
      })
      .catch(() => {});

    m.redraw();
  }

  private ago(unix: number): string {
    const mins = Math.max(1, Math.round((Date.now() / 1000 - unix) / 60));

    if (mins < 60) return t('ago_minutes', { count: mins }) as unknown as string;

    const hours = Math.round(mins / 60);

    return hours < 48
      ? (t('ago_hours', { count: hours }) as unknown as string)
      : (t('ago_days', { count: Math.round(hours / 24) }) as unknown as string);
  }
}
