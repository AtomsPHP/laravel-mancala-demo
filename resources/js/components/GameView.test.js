import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GameView from './GameView.vue';

const initialState = {
  status: 'active',
  pits: Array(12).fill(4),
  stores: [0, 0],
  turn: 0,
  revision: 3,
  winner: null,
};

class FakeWebSocket {
  static OPEN = 1;
  static instances = [];

  constructor(url) {
    this.url = url;
    this.readyState = FakeWebSocket.OPEN;
    this.listeners = {};
    this.sent = [];
    FakeWebSocket.instances.push(this);
  }

  addEventListener(kind, listener) {
    this.listeners[kind] = listener;
  }

  emit(kind, value = {}) {
    this.listeners[kind]?.(value);
  }

  send(payload) {
    this.sent.push(payload);
  }

  close() {
    this.readyState = 3;
  }
}

async function welcome(socket, overrides = {}) {
  socket.emit('message', {
    data: JSON.stringify({
      kind: 'welcome',
      role: 'player',
      seat: 0,
      state: initialState,
      ...overrides,
    }),
  });
  await flushPromises();
}

describe('GameView', () => {
  beforeEach(() => {
    FakeWebSocket.instances = [];
    vi.stubGlobal('WebSocket', FakeWebSocket);
    vi.spyOn(Storage.prototype, 'getItem').mockReturnValue('browser-test');
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })));
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('connects directly to the game channel and sends revision-guarded moves', async () => {
    const wrapper = mount(GameView, {
      props: { gameId: 'a'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example' },
    });
    const socket = FakeWebSocket.instances[0];

    expect(socket.url).toContain(`/ws/MancalaGame/${'a'.repeat(32)}`);
    expect(socket.url).toContain('channels=game');
    expect(socket.url).toContain('client_id=browser-test');
    await welcome(socket);

    await wrapper.findAll('.pit:not([disabled])')[2].trigger('click');
    expect(JSON.parse(socket.sent[0])).toEqual({ kind: 'move', pit: 2, revision: 3 });
  });

  it('keeps explicit observers read-only after the welcome snapshot', async () => {
    const wrapper = mount(GameView, {
      props: { gameId: 'b'.repeat(32), mode: 'observe', atomsEndpoint: 'https://worker.example' },
    });
    const socket = FakeWebSocket.instances[0];
    await welcome(socket, { role: 'observer', seat: null });

    expect(socket.url).toContain('mode=observe');
    expect(wrapper.text()).toContain('watching live');
    expect(wrapper.findAll('.pit:not([disabled])')).toHaveLength(0);
  });

  it('animates a move without cloning a reactive proxy', async () => {
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
    vi.useFakeTimers();
    const wrapper = mount(GameView, {
      props: { gameId: 'd'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example', stoneDropMs: 0 },
    });
    const socket = FakeWebSocket.instances[0];
    await welcome(socket);

    socket.emit('message', {
      data: JSON.stringify({
        kind: 'moved',
        actor: 0,
        source_pit: 2,
        path: [{ kind: 'pit', index: 3 }, { kind: 'store', player: 0 }],
        capture: null,
        extra_turn: true,
        state: { ...initialState, pits: initialState.pits.map((n, i) => i === 2 ? 0 : i === 3 ? 5 : n), stores: [1, 0], revision: 4 },
      }),
    });
    await vi.runAllTimersAsync();
    await flushPromises();
    vi.useRealTimers();

    expect(wrapper.text()).toContain('Player 1 earned another turn.');
    expect(wrapper.find('[aria-label="Player 1 pit 4, 5 stones"]').exists()).toBe(true);
  });

  it('marks an expired game without cloning a reactive proxy', async () => {
    const wrapper = mount(GameView, {
      props: { gameId: 'e'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example' },
    });
    const socket = FakeWebSocket.instances[0];
    await welcome(socket);

    socket.emit('message', { data: JSON.stringify({ kind: 'expired' }) });
    await flushPromises();

    expect(wrapper.text()).toContain('The 24-hour game window has ended.');
    expect(wrapper.text()).toContain('Only the memory remains.');
  });

  it('reconciles a reduced-motion move to its authoritative snapshot', async () => {
    const wrapper = mount(GameView, {
      props: { gameId: 'c'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example' },
    });
    const socket = FakeWebSocket.instances[0];
    await welcome(socket);

    socket.emit('message', {
      data: JSON.stringify({
        kind: 'moved',
        actor: 0,
        source_pit: 2,
        path: [{ kind: 'pit', index: 3 }],
        capture: null,
        extra_turn: false,
        state: { ...initialState, pits: initialState.pits.map((n, i) => i === 2 ? 0 : i === 3 ? 5 : n), turn: 1, revision: 4 },
      }),
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Player 1 moved.');
    expect(wrapper.find('[aria-label="Player 1 pit 3, 0 stones"]').exists()).toBe(true);
    expect(wrapper.findAll('.pit:not([disabled])')).toHaveLength(0);
  });
});
