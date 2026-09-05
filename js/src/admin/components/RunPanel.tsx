import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

declare const m: any;

const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-millwright.admin.' + k, p);

const PHASES = ['plan', 'fetch', 'apply', 'finalise'];

interface RunPanelAttrs {
  run: any;
  driver: string | null;
  busy: boolean;
  stale: boolean;
  rollbackNote: string | null;
  onprogress: (data: any) => void;
  ondone: (run: any) => void;
  onrollback: (data: any) => void;
  ondismiss: () => void;
  onerror: (message: string) => void;
}

/**
 * What is happening, while it happens.
 *
 * 🚨 This panel is the product. An update that works but shows a spinner is the
 * thing being replaced — the complaint that started all of this was not "it
 * failed", it was "the status has been showing running for a while and I don't
 * know what is going on". So every piece of state the server knows is on screen:
 * which phase, which item of how many, every line of the log, how long since
 * anything moved, and which driver is turning the handle.
 *
 * 🚨 It also never advertises progress it has not been told about. When a poll
 * comes back saying another driver holds the run, that is shown as exactly that
 * rather than as a stalled bar.
 */
export default class RunPanel extends Component<RunPanelAttrs> {
  /** Consecutive failed polls. A host hiccup is not a failed update. */
  private misses = 0;
  private polling = false;
  private rollingBack = false;

  oncreate(vnode: any) {
    super.oncreate(vnode);
    this.poll();
  }

  onremove() {
    // Stops the loop rescheduling itself once the page is gone.
    this.polling = false;
  }

  view() {
    const run = this.attrs.run;
    if (!run) return null;

    const failed = run.state === 'failed';
    const done = run.state === 'done';
    const rolled = run.state === 'rolled-back';

    return (
      <div className={'Millwright-run' + (failed ? ' Millwright-run--failed' : '')}>
        <div className="Millwright-runHead">
          <h3>{done ? t('run_done') : rolled ? t('run_rolled_back') : failed ? t('run_failed') : t('run_working')}</h3>
          {this.attrs.driver ? <span className="Millwright-driver">{this.attrs.driver}</span> : null}
        </div>

        {!done && !failed && !rolled ? this.phases(run) : null}
        {!done && !failed && !rolled ? this.bar(run) : null}

        {failed ? (
          <div className="Millwright-error">
            <div className="Millwright-errorWhere">{t('failed_at', { step: run.errorStep })}</div>
            <pre className="Millwright-errorText">{run.error}</pre>
            {/*
              * 🚨 Offered on a FAILURE, not just on a finished run. The journal
              * records each move before it is made, so a half-finished apply is
              * exactly as reversible as a complete one — and this is the moment
              * somebody most needs to know that.
              */}
            <button className="Button" disabled={this.rollingBack} onclick={() => this.rollback()}>
              {this.rollingBack ? t('rolling_back') : t('roll_back')}
            </button>
          </div>
        ) : null}

        {this.attrs.rollbackNote ? <div className="Millwright-next">{this.attrs.rollbackNote}</div> : null}

        {this.misses > 2 && !done && !failed && !rolled ? (
          <div className="Millwright-stall">{t('poll_failing', { count: this.misses })}</div>
        ) : null}

        {this.attrs.busy && !done && !failed && !rolled ? (
          <div className="Millwright-stall">{t('another_driver')}</div>
        ) : null}

        {/*
          * 🚨 Every one of these is silenced once the run has ended. "Nothing
          * has moved for a couple of minutes" is true of a failed run and
          * useless — it points somebody at a stall when the panel above already
          * says exactly what went wrong.
          */}
        {this.attrs.stale && !done && !failed && !rolled ? (
          <div className="Millwright-stall">{t('nothing_moved')}</div>
        ) : null}

        {done || rolled || failed ? (
          <button className="Button Button--link Millwright-dismiss" onclick={() => this.attrs.ondismiss()}>
            {t('dismiss')}
          </button>
        ) : null}

        <ol className="Millwright-log">
          {(run.log || []).map((line: string, i: number) => (
            <li key={i}>{line}</li>
          ))}
        </ol>
      </div>
    );
  }

  phases(run: any) {
    const at = PHASES.indexOf(run.phase);

    return (
      <ol className="Millwright-phases">
        {PHASES.map((p, i) => (
          <li key={p} className={'Millwright-phase' + (i < at ? ' is-done' : i === at ? ' is-now' : '')}>
            {t('phase_' + p)}
          </li>
        ))}
      </ol>
    );
  }

  bar(run: any) {
    const total = (run.items || []).length;
    const item = total ? run.items[Math.min(run.index, total - 1)] : null;

    return (
      <div className="Millwright-progress">
        <div className="Millwright-progressBar">
          <div
            className="Millwright-progressFill"
            style={{ width: (total ? Math.round((run.index / total) * 100) : 0) + '%' }}
          />
        </div>
        {/*
          * 🚨 The item's own name, not "step 3 of 7". A package name tells you
          * what is being touched right now; a number tells you nothing you can
          * act on if it stops.
          */}
        <div className="Millwright-progressText">
          {total ? t('working_on', { item, index: Math.min(run.index + 1, total), total }) : t('working_out')}
        </div>
      </div>
    );
  }

  poll() {
    if (this.polling) return;
    this.polling = true;
    this.tick();
  }

  tick() {
    if (!this.polling) return;

    app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/millwright/step' })
      .then((data: any) => {
        this.misses = 0;
        this.attrs.onprogress(data);

        if (data.idle) {
          this.polling = false;
          this.attrs.ondone(data.run);
          return;
        }

        m.redraw();
        setTimeout(() => this.tick(), 1500);
      })
      .catch(() => {
        /*
         * 🚨 A failed poll is never a failed update, and this is the difference
         * between the two designs. The run's state is on disk; a 502 from a
         * host that cut the request means this one call did not land, and the
         * next one picks up where the last left off. Backing off keeps a
         * struggling host from being hammered while it recovers.
         */
        this.misses++;
        m.redraw();
        setTimeout(() => this.tick(), Math.min(1500 * this.misses, 15000));
      });
  }

  rollback() {
    this.rollingBack = true;
    this.polling = false;
    m.redraw();

    app
      .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/millwright/rollback' })
      .then((data: any) => {
        this.rollingBack = false;
        this.attrs.onrollback(data);
        m.redraw();
      })
      .catch((e: any) => {
        this.rollingBack = false;
        this.attrs.onerror(e?.response?.error || t('rollback_failed'));
        m.redraw();
      });
  }
}
