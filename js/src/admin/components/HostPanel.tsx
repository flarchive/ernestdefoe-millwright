import Component from 'flarum/common/Component';

declare const m: any;

interface Check { id: string; ok: boolean; warn: boolean; what: string; why: string }

/**
 * What this host will and will not let Millwright do — shown before anything is
 * pressed, which is the entire point.
 *
 * 🚨 Every row says what it MEANS, not just what it is. "memory_limit: 128M" is
 * a fact; "Composer cannot resolve dependencies here at all — ask your host for
 * 256 MB" is something somebody can act on. A panel of facts without
 * consequences is the spinner problem wearing a different hat.
 */
export default class HostPanel extends Component {
  view(vnode: any) {
    const host = vnode.attrs.host;

    if (!host) return null;

    return (
      <div className="Millwright-host">
        <div className="Millwright-summary">
          <h3>This host</h3>
          <p>{host.summary}</p>
        </div>

        <div className="Millwright-checks">
          {(host.checks as Check[]).map((c) => (
            <div className="Millwright-check" key={c.id}>
              <div aria-hidden="true">{c.warn ? '⚠️' : c.ok ? '✅' : '❌'}</div>
              <div>
                <div className="Millwright-check-what">{c.what}</div>
                <div className="Millwright-check-why">{c.why}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    );
  }
}
