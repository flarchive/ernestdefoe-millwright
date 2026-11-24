import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type { HistoryEntry, Installed, RunState } from '../types';

export interface HistoryTabAttrs extends ComponentAttrs {
  history: HistoryEntry[];
  installed: Installed[];
}

/**
 * What changed on this forum, and when — the "recently updated" list an app
 * store keeps. Read from storage/millwright/history.json (see History.php).
 */
export default class HistoryTab extends Component<HistoryTabAttrs> {
  view() {
    const history = this.attrs.history || [];

    if (!history.length) return <div className="Millwright-empty">{app.translator.trans('ernestdefoe-millwright.admin.sections.queue.none')}</div>;

    const names: Record<string, string> = {};
    (this.attrs.installed || []).forEach((e) => (names[e.package] = e.name));
    names['flarum/core'] = names['flarum/core'] || 'Flarum';

    return (
      <ol className="Millwright-history">
        {history.map((entry) => {
          // The packages somebody asked for lead; what came along with them follows.
          const requested = entry.requested || [];
          const changes = [...(entry.changes || [])].sort((a, b) => Number(requested.includes(b.package)) - Number(requested.includes(a.package)));
          const when = dayjs(entry.at * 1000);

          return (
            <li key={entry.id} className={'Millwright-historyItem is-' + entry.state}>
              <div className="Millwright-historyHead">
                <span className={'Millwright-tag' + this.tone(entry.state)}>{this.outcome(entry)}</span>
                <time datetime={when.toISOString()} title={when.format('LLLL')}>
                  {when.format('LLL')}
                </time>
              </div>
              <ul className="Millwright-historyChanges">
                {changes.length ? (
                  changes.map((c) => (
                    <li key={c.package}>
                      <b>{names[c.package] || c.package}</b> <span className="Millwright-pkg">{c.package}</span>{' '}
                      <span className="Millwright-historyVersions">
                        {c.op === 'add'
                          ? app.translator.trans('ernestdefoe-millwright.admin.sections.queue.new_version', { version: c.to })
                          : c.op === 'remove'
                          ? app.translator.trans('ernestdefoe-millwright.admin.sections.queue.removed_version', { version: c.from })
                          : `${c.from} → ${c.to}`}
                      </span>
                    </li>
                  ))
                ) : (
                  <li>{requested.join(', ') || app.translator.trans('ernestdefoe-millwright.admin.sections.queue.nothing')}</li>
                )}
              </ul>
              {entry.migrations ? (
                <div className="Millwright-historyNote">
                  {app.translator.trans('ernestdefoe-millwright.admin.sections.queue.migrations', { count: entry.migrations })}
                </div>
              ) : null}
            </li>
          );
        })}
      </ol>
    );
  }

  private outcome(entry: HistoryEntry) {
    if (entry.state === 'rolled-back') return app.translator.trans('ernestdefoe-millwright.admin.sections.queue.statuses.rolled_back');
    if (entry.state === 'failed') return app.translator.trans('ernestdefoe-millwright.admin.sections.queue.statuses.failure');

    return entry.mode === 'install'
      ? app.translator.trans('ernestdefoe-millwright.admin.sections.queue.operations.extension_install')
      : entry.mode === 'remove'
      ? app.translator.trans('ernestdefoe-millwright.admin.sections.queue.operations.extension_remove')
      : app.translator.trans('ernestdefoe-millwright.admin.sections.queue.operations.extension_update');
  }

  private tone(state: RunState): string {
    return state === 'done' ? ' Millwright-tag--ok' : state === 'failed' ? ' Millwright-tag--warn' : ' Millwright-tag--muted';
  }
}
