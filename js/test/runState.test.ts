import { describe, expect, it } from 'vitest';
import {
  cardOffers,
  dismissalApplies,
  hidesPage,
  pollOutcome,
  runIsLive,
  runIsOver,
  showingRun,
  sortForGrid,
} from '../src/admin/runState';

/*
 * Every block below is a bug that reached a real forum. They are grouped by the
 * failure rather than by the function, because the point of this file is that
 * the next one of these is caught here instead of by Ernest.
 */

describe('a finished run must not take over the page', () => {
  const finished = { id: 'r1', state: 'done' };
  const running = { id: 'r2', state: 'running' };

  /*
   * 🚨 THE BUG. `showingRun()` was `!!run && !dismissed` and was used both to
   * draw the panel AND to hide the tabs. `dismissed` was component state, so it
   * reset on every page load: after any run had ever happened, opening
   * Millwright showed nothing but that run's log — no tabs, no extension grid,
   * no Update buttons, no route to them.
   */
  it('shows the panel for a finished run but leaves the page usable', () => {
    expect(showingRun(finished, false)).toBe(true);
    expect(hidesPage(finished, false)).toBe(false);
  });

  it('gives the whole page to a live run', () => {
    expect(showingRun(running, false)).toBe(true);
    expect(hidesPage(running, false)).toBe(true);
  });

  it('hides nothing when there has never been a run', () => {
    expect(showingRun(null, false)).toBe(false);
    expect(hidesPage(null, false)).toBe(false);
  });

  it('treats a failed or rolled-back run as finished, not as still going', () => {
    for (const state of ['failed', 'rolled-back']) {
      expect(hidesPage({ id: 'r', state }, false)).toBe(false);
      expect(runIsOver({ id: 'r', state })).toBe(true);
    }
  });

  it('does not mistake an unknown state for a live run', () => {
    expect(runIsLive({ id: 'r', state: 'something-new' })).toBe(false);
    expect(runIsOver({ id: 'r', state: 'something-new' })).toBe(false);
  });
});

describe('dismissing a run', () => {
  it('stays dismissed across a reload, for that run', () => {
    expect(dismissalApplies({ id: 'r1', state: 'done' }, 'r1')).toBe(true);
  });

  /* A dismissal that leaked onto the next run would hide a fresh failure. */
  it('never pre-dismisses a different run', () => {
    expect(dismissalApplies({ id: 'r2', state: 'done' }, 'r1')).toBe(false);
  });

  /*
   * 🚨 Dismissing must not become a way to hide an update that is halfway
   * through applying itself.
   */
  it('is never restored for a run that is still going', () => {
    expect(dismissalApplies({ id: 'r1', state: 'running' }, 'r1')).toBe(false);
  });

  it('copes with storage being unavailable', () => {
    expect(dismissalApplies({ id: 'r1', state: 'done' }, null)).toBe(false);
  });
});

describe('a poll that cannot drive the run', () => {
  it('drives while nothing is failing', () => {
    expect(pollOutcome(null, 0)).toEqual({ action: 'drive', delayMs: 1500, message: 'none' });
  });

  /*
   * 🚨 THE BUG. /millwright/step is a POST that takes the lock; it was the
   * panel's only source of state. When it failed, the panel froze on whatever
   * it mounted with while the queue worker carried the update to completion —
   * so a finished update looked stuck on step one. Watching is a different job
   * from driving, and the read-only endpoint can always do it.
   */
  it('falls back to watching rather than giving up', () => {
    expect(pollOutcome(500, 1).action).toBe('watch');
    expect(pollOutcome(400, 3).action).toBe('watch');
  });

  it('backs off, but not without limit', () => {
    expect(pollOutcome(500, 1).delayMs).toBe(1500);
    expect(pollOutcome(500, 4).delayMs).toBe(6000);
    expect(pollOutcome(500, 100).delayMs).toBe(15000);
  });

  /* A 4xx is an expired session far more often than a broken update. */
  it('names an expired session instead of blaming the update', () => {
    expect(pollOutcome(401, 3).message).toBe('unauthorised');
    expect(pollOutcome(400, 3).message).toBe('unauthorised');
    expect(pollOutcome(502, 3).message).toBe('failing');
    expect(pollOutcome(null, 3).message).toBe('failing');
  });
});

describe('what a card in the grid offers', () => {
  it('offers the update, and says so, when there is one', () => {
    const offers = cardOffers({ update: { from: '1.0.0', to: '1.1.0' }, enabled: true });

    expect(offers.badge).toBe(true);
    expect(offers.update).toBe(true);
  });

  it('offers nothing to update when there is no newer version', () => {
    const offers = cardOffers({ update: null, enabled: true });

    expect(offers.badge).toBe(false);
    expect(offers.update).toBe(false);
  });

  /*
   * 🚨 A path install is a symlink into a checkout on this machine — how every
   * extension developer runs their own work. Millwright will not replace one,
   * and offering the button then refusing is a worse way to learn that.
   */
  it('never offers to touch a local checkout', () => {
    const offers = cardOffers({ update: { from: '1.0.0', to: '1.1.0' }, enabled: false, pathInstall: true });

    expect(offers.update).toBe(false);
    expect(offers.remove).toBe(false);
    // It still SAYS a newer version exists; it just will not act on it.
    expect(offers.badge).toBe(true);
  });

  it('only offers to remove something that is switched off', () => {
    expect(cardOffers({ enabled: true }).remove).toBe(false);
    expect(cardOffers({ enabled: false }).remove).toBe(true);
  });
});

describe('grid ordering', () => {
  it('puts anything with a newer version first', () => {
    const sorted = sortForGrid([
      { id: 'a', update: null },
      { id: 'b', update: { from: '1.0.0', to: '1.1.0' } },
      { id: 'c', update: null },
    ] as any);

    expect(sorted.map((e: any) => e.id)).toEqual(['b', 'a', 'c']);
  });

  it('does not mutate what it was given', () => {
    const input = [{ id: 'a', update: null }, { id: 'b', update: { from: '1', to: '2' } }] as any;
    sortForGrid(input);

    expect(input.map((e: any) => e.id)).toEqual(['a', 'b']);
  });
});
