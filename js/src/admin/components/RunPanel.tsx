import app from 'flarum/admin/app';
import t from '../t';
import extractText from 'flarum/common/utils/extractText';
import apiUrl from '../apiUrl';
import { pollOutcome, runIsOver, shouldPoll } from '../runState';
import Component from 'flarum/common/Component';

declare const m: any;


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
  /** True once we have fallen back to reading progress instead of driving it. */
  private watching = false;
  /** The HTTP status of the last failed drive, so the panel can be specific. */
  private lastStatus: number | null = null;
  /** What the last failure means and what to do next — see runState.pollOutcome. */
  private outcome = pollOutcome(null, 0);

  /** The run the loop is following, so a new one starts with a clean slate. */
  private following: string | null = null;

  oncreate(vnode: any) {
    super.oncreate(vnode);
    this.follow();
  }

  /*
   * 🚨 Checked after every redraw, not only on creation. A finished run keeps
   * this panel mounted, so the next Update arrives as a new `run` attr on the
   * SAME component — and when polling only began in oncreate, nothing polled
   * that run at all. See runState.shouldPoll.
   */
  onupdate(vnode: any) {
    super.onupdate(vnode);
    this.follow();
  }

  private follow() {
    const run = this.attrs.run;

    if (run?.id && run.id !== this.following) {
      // A different run: the last one's failures say nothing about this one.
      this.following = run.id;
      this.misses = 0;
      this.watching = false;
      this.lastStatus = null;
      this.outcome = pollOutcome(null, 0);
    }

    if (!this.rollingBack && shouldPoll(run, this.polling)) {
      this.poll();
    }
  }

  onremove() {
    // Stops the loop rescheduling itself once the page is gone.
    this.polling = false;
  }

  /** Which extensions this run is for: the names, or the first few and a count. */
  target(packages: string[]): string {
    if (packages.length <= 3) return packages.join(', ');

    return extractText(t('run_target_more', { shown: packages.slice(0, 2).join(', '), count: packages.length - 2 }));
  }

  view() {
    const run = this.attrs.run;
    if (!run) return null;

    const failed = run.state === 'failed';
    const done = run.state === 'done';
    const rolled = run.state === 'rolled-back';
    const mode = ['install', 'remove'].includes(run.mode) ? run.mode : 'update';
    const target = this.target(run.packages || []);

    return (
      <div className={'Millwright-run' + (failed ? ' Millwright-run--failed' : '')}>
        <div className="Millwright-runHead">
          <div>
            <h3>{done ? t('run_done') : rolled ? t('run_rolled_back') : failed ? t('run_failed') : target ? t('run_target_' + mode, { packages: target }) : t('run_working')}</h3>
            {target && (done || failed || rolled) ? <div className="Millwright-runTarget">{target}</div> : null}
          </div>
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

        {/*
          * 🚨 A rolled-back run shows its reason too, and this used to be the
          * one state that did not.
          *
          * When the admin pressed the button they knew why. Now a run can undo
          * ITSELF — the site stopped answering and it put everything back — and
          * "Rolled back" on its own leaves them with a forum that works, an
          * update that vanished, and no idea which of the two is the problem.
          * The sentence names the extension and the line.
          *
          * No Roll back button here: the tree is already correct, and offering
          * to undo it again is the first thing a worried admin would press.
          */}
        {rolled && run.error ? (
          <div className="Millwright-error">
            <div className="Millwright-errorWhere">{t('failed_at', { step: run.errorStep })}</div>
            <pre className="Millwright-errorText">{run.error}</pre>
          </div>
        ) : null}

        {this.attrs.rollbackNote ? <div className="Millwright-next">{this.attrs.rollbackNote}</div> : null}

        {this.misses > 2 && !done && !failed && !rolled ? (
          <div className="Millwright-stall">
            {/*
              * 🚨 Says WHICH problem. A 400 or 401 here is an expired session —
              * the update is fine and a reload fixes the screen — and telling
              * somebody "nothing has moved" instead sends them looking at the
              * update.
              */}
            {this.outcome.message === 'unauthorised'
              ? t('poll_unauthorised')
              : t('poll_failing', { count: this.misses })}
            {this.watching ? ' ' + t('watching_only') : ''}
          </div>
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

        {/*
          * 🚨 A FINISHED update can be undone too, and the screen used to
          * offer that only on a failure. The endpoint, the journal and the
          * trash all exist for exactly this — "it worked, and I wish it had
          * not" is the rollback people actually need — so a button that only
          * appears when something broke left the feature unreachable.
          *
          * But on a finished run it is the last resort, not the next step:
          * Dismiss leads, and Undo is a quiet link after it. "Put everything
          * back" sat first, as a full button, and read to a tester like a
          * cleanup of leftover files (ClaudiusH, 2026-10-07).
          */}
        {done || rolled || failed ? (
          <div className="Millwright-runActions">
            <button className="Button" onclick={() => this.attrs.ondismiss()}>
              {t('dismiss')}
            </button>
            {done ? (
              <button
                className="Button Button--link Millwright-undo"
                disabled={this.rollingBack}
                onclick={() => confirm(t('roll_back_confirm')) && this.rollback()}
              >
                {this.rollingBack ? t('rolling_back') : t('roll_back')}
              </button>
            ) : null}
          </div>
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
          {/*
            * 🚨 "Asking Composer what this involves" is the PLAN phase's
            * sentence. Every phase starts with no items for a moment, and it
            * used to say that during the download, the swap and the finish too.
            */}
          {total
            ? t('working_on', { item, index: Math.min(run.index + 1, total), total })
            : run.phase === 'plan'
              ? t('working_out')
              : null}
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
      .request({ method: 'POST', url: apiUrl() + '/millwright/step' })
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
      .catch((e: any) => {
        /*
         * 🚨 A failed poll is never a failed update, and this is the difference
         * between the two designs. The run's state is on disk; a 502 from a
         * host that cut the request means this one call did not land, and the
         * next one picks up where the last left off. Backing off keeps a
         * struggling host from being hammered while it recovers.
         */
        this.misses++;
        this.lastStatus = e?.status ?? null;
        this.outcome = pollOutcome(this.lastStatus, this.misses);

        /*
         * 🚨 Driving the run and WATCHING it are two different jobs, and only
         * one of them is this panel's reason to exist.
         *
         * /millwright/step is a POST: it takes the lock and does an item. When
         * it fails — an expired session, a proxy that strips the write, a host
         * that 502s under a Composer install — the panel used to show nothing
         * at all, frozen on whatever it had when it mounted, while the queue
         * worker carried the update to completion behind it. Ernest watched
         * exactly that happen twice: "it stayed on the first step the whole
         * time", on a run that had in fact finished.
         *
         * /millwright/state is a GET and is documented as never advancing
         * anything. So when we cannot drive, we watch. The run still moves —
         * the worker is doing it — and the screen finally says so.
         */
        this.watch();
      });
  }

  /**
   * Read-only progress, for when we cannot drive.
   *
   * Keeps polling on the same backoff so a transient failure recovers into
   * driving again by itself.
   */
  private watch() {
    app
      .request({ method: 'GET', url: apiUrl() + '/millwright/state' })
      .then((data: any) => {
        this.watching = true;

        if (data.run) {
          this.attrs.onprogress({ run: data.run, busy: true, stale: data.runIsStale });

          if (runIsOver(data.run)) {
            this.polling = false;
            this.attrs.ondone(data.run);
            m.redraw();

            return;
          }
        }

        m.redraw();
        setTimeout(() => this.tick(), this.outcome.delayMs);
      })
      .catch(() => {
        // Both endpoints unreachable. Now it really is a connectivity problem.
        m.redraw();
        setTimeout(() => this.tick(), this.outcome.delayMs);
      });
  }

  rollback() {
    this.rollingBack = true;
    this.polling = false;
    m.redraw();

    app
      .request({ method: 'POST', url: apiUrl() + '/millwright/rollback' })
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
