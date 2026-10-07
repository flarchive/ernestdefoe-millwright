import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import t from '../t';

/**
 * What changed on this forum, and when — the "recently updated" list an app
 * store keeps. Read from storage/millwright/history.json (see History.php).
 */
export default class HistoryTab extends Component<{ history: any[]; installed: any[] }> {
  view() {
    const history = this.attrs.history || [];

    if (!history.length) return <div className="Millwright-empty">{t('history_empty')}</div>;

    const names: Record<string, string> = {};
    (this.attrs.installed || []).forEach((e: any) => (names[e.package] = e.name));
    names['flarum/core'] = names['flarum/core'] || 'Flarum';

    return (
      <ol className="Millwright-history">
        {history.map((entry: any) => {
          // The packages somebody asked for lead; what came along with them follows.
          const changes = [...(entry.changes || [])].sort(
            (a: any, b: any) => Number((entry.requested || []).includes(b.package)) - Number((entry.requested || []).includes(a.package))
          );
          const when = dayjs(entry.at * 1000);

          return (
            <li key={entry.id} className={'Millwright-historyItem is-' + entry.state}>
              <div className="Millwright-historyHead">
                <span className={'Millwright-tag' + this.tone(entry.state)}>{t('history_' + this.outcome(entry))}</span>
                <time datetime={when.toISOString()} title={when.format('LLLL')}>
                  {when.format('LLL')}
                </time>
              </div>
              <ul className="Millwright-historyChanges">
                {changes.length ? (
                  changes.map((c: any) => (
                    <li key={c.package}>
                      <b>{names[c.package] || c.package}</b> <span className="Millwright-pkg">{c.package}</span>{' '}
                      <span className="Millwright-historyVersions">
                        {c.op === 'add' ? t('history_new', { version: c.to }) : c.op === 'remove' ? t('history_removed_from', { version: c.from }) : `${c.from} → ${c.to}`}
                      </span>
                    </li>
                  ))
                ) : (
                  <li>{(entry.requested || []).join(', ') || t('history_nothing')}</li>
                )}
              </ul>
              {entry.migrations > 0 ? <div className="Millwright-historyNote">{t('history_migrations', { count: entry.migrations })}</div> : null}
            </li>
          );
        })}
      </ol>
    );
  }

  private outcome(entry: any): string {
    if (entry.state === 'rolled-back') return 'undone';
    if (entry.state === 'failed') return 'failed';
    return entry.mode === 'install' ? 'installed' : entry.mode === 'remove' ? 'removed' : 'updated';
  }

  private tone(state: string): string {
    return state === 'done' ? ' Millwright-tag--ok' : state === 'failed' ? ' Millwright-tag--warn' : ' Millwright-tag--muted';
  }
}
