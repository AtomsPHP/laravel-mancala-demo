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

// Mounting no longer opens a socket synchronously: the component mints a
// connection ticket first, so every test has to let that request settle.
async function mountGame(props) {
  const wrapper = mount(GameView, { props });
  await flushPromises();
  return { wrapper, socket: FakeWebSocket.instances.at(-1) };
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
  let minted;

  beforeEach(() => {
    FakeWebSocket.instances = [];
    minted = 0;
    vi.stubGlobal('WebSocket', FakeWebSocket);
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ticket: `ticket-${++minted}` }),
    })));
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })));
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('connects directly to the game channel and sends revision-guarded moves', async () => {
    const { wrapper, socket } = await mountGame({
      gameId: 'a'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example',
    });

    expect(socket.url).toContain(`/ws/MancalaGame/${'a'.repeat(32)}`);
    expect(socket.url).toContain('channels=game');
    // The seat key is a signed claim inside the ticket, never a query param the
    // browser chooses — sending one here would be overridden anyway.
    expect(socket.url).toContain('ticket=ticket-1');
    expect(socket.url).not.toContain('client_id');
    // Session-authenticated endpoint, so it rides the web middleware group.
    expect(fetch.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('test-token');
    await welcome(socket);

    await wrapper.findAll('.pit:not([disabled])')[2].trigger('click');
    expect(JSON.parse(socket.sent[0])).toEqual({ kind: 'move', pit: 2, revision: 3 });
  });

  it('keeps explicit observers read-only after the welcome snapshot', async () => {
    const { wrapper, socket } = await mountGame({
      gameId: 'b'.repeat(32), mode: 'observe', atomsEndpoint: 'https://worker.example',
    });
    await welcome(socket, { role: 'observer', seat: null });

    expect(socket.url).toContain('mode=observe');
    expect(wrapper.text()).toContain('watching live');
    expect(wrapper.findAll('.pit:not([disabled])')).toHaveLength(0);
  });

  it('animates a move without cloning a reactive proxy', async () => {
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false })));
    const { wrapper, socket } = await mountGame({
      gameId: 'd'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example', stoneDropMs: 0,
    });
    await welcome(socket);
    // Fake timers go up only after the mint has settled: the mint is a real
    // promise, and freezing time before it resolves would deadlock the mount.
    vi.useFakeTimers();

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
    const { wrapper, socket } = await mountGame({
      gameId: 'e'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example',
    });
    await welcome(socket);

    socket.emit('message', { data: JSON.stringify({ kind: 'expired' }) });
    await flushPromises();

    expect(wrapper.text()).toContain('The 24-hour game window has ended.');
    expect(wrapper.text()).toContain('Only the memory remains.');
  });

  it('mints a fresh ticket for every reconnect', async () => {
    vi.useFakeTimers();
    const { socket } = await mountGame({
      gameId: 'f'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example', reconnectMs: 10,
    });
    await welcome(socket);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe(`/api/games/${'f'.repeat(32)}/ticket`);

    // A ticket lives about a minute, so reusing the first one on a reconnect
    // would present a credential the Worker has already aged out.
    socket.emit('close');
    await vi.advanceTimersByTimeAsync(10);
    await flushPromises();
    vi.useRealTimers();

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(FakeWebSocket.instances[1].url).toContain('ticket=ticket-2');
  });

  it('backs off instead of stampeding the mint endpoint', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 503 })));
    const { wrapper } = await mountGame({
      gameId: '0'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example',
      reconnectMs: 100, reconnectMaxMs: 1000,
    });

    expect(FakeWebSocket.instances).toHaveLength(0);
    expect(wrapper.text()).toContain('Could not reach the table.');
    expect(fetch).toHaveBeenCalledTimes(1);

    // Each retry costs a mint on Laravel and another on the Worker, so the
    // delay doubles rather than holding at reconnectMs.
    await vi.advanceTimersByTimeAsync(100);
    expect(fetch).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(100);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(100);
    expect(fetch).toHaveBeenCalledTimes(3);
    vi.useRealTimers();
  });

  it('stops retrying when the session has expired', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 419 })));
    const { wrapper } = await mountGame({
      gameId: '1'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example', reconnectMs: 10,
    });

    // Retrying a dead session never recovers it; only a reload does.
    expect(wrapper.text()).toContain('Reload to rejoin the table.');
    await vi.advanceTimersByTimeAsync(5000);
    expect(fetch).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });

  it('does not open a socket if the component unmounts mid-mint', async () => {
    let release;
    vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
      release = () => resolve({ ok: true, status: 200, json: async () => ({ ticket: 'late' }) });
    })));

    const wrapper = mount(GameView, {
      props: { gameId: '2'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example' },
    });
    wrapper.unmount();
    release();
    await flushPromises();

    expect(FakeWebSocket.instances).toHaveLength(0);
  });

  it('reconciles a reduced-motion move to its authoritative snapshot', async () => {
    const { wrapper, socket } = await mountGame({
      gameId: 'c'.repeat(32), mode: 'player', atomsEndpoint: 'https://worker.example',
    });
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
