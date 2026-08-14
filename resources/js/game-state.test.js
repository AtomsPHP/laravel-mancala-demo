import { describe, expect, it } from 'vitest';
import { BOTTOM_PITS, TOP_PITS, applyDrop, beginAnimatedMove, canPlay, eventFromFrame } from './game-state.js';

const active = {
  status: 'active',
  pits: Array(12).fill(4),
  stores: [0, 0],
  turn: 0,
  revision: 0,
};

describe('Mancala client state', () => {
  it('renders the far row in physical left-to-right order', () => {
    expect(TOP_PITS).toEqual([11, 10, 9, 8, 7, 6]);
    expect(BOTTOM_PITS).toEqual([0, 1, 2, 3, 4, 5]);
  });

  it('allows only the current player to choose a non-empty pit on their side', () => {
    expect(canPlay(active, 0, 2)).toBe(true);
    expect(canPlay(active, 0, 8)).toBe(false);
    expect(canPlay(active, 1, 8)).toBe(false);
    expect(canPlay({ ...active, pits: active.pits.map((n, i) => i === 2 ? 0 : n) }, 0, 2)).toBe(false);
    expect(canPlay(active, 0, 2, true)).toBe(false);
  });

  it('animates from the old board without mutating the authoritative snapshot', () => {
    const started = beginAnimatedMove(active, 2);
    const pitDrop = applyDrop(started, { kind: 'pit', index: 3 });
    const storeDrop = applyDrop(pitDrop, { kind: 'store', player: 0 });

    expect(active.pits[2]).toBe(4);
    expect(started.pits[2]).toBe(0);
    expect(storeDrop.pits[3]).toBe(5);
    expect(storeDrop.stores[0]).toBe(1);
  });

  it('unwraps runtime broadcast frames while retaining direct frames', () => {
    expect(eventFromFrame({ kind: 'broadcast', payload: { kind: 'moved' } })).toEqual({ kind: 'moved' });
    expect(eventFromFrame({ kind: 'welcome' })).toEqual({ kind: 'welcome' });
  });
});
