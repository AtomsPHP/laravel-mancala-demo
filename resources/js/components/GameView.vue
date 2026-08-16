<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import AtomsMark from './AtomsMark.vue';
import MancalaBoard from './MancalaBoard.vue';
import { applyDrop, beginAnimatedMove, eventFromFrame } from '../game-state.js';
import { csrfToken } from '../csrf.js';

const props = defineProps({
  gameId: { type: String, required: true },
  mode: { type: String, default: 'player' },
  atomsEndpoint: { type: String, required: true },
  stoneDropMs: { type: Number, default: 220 },
  reconnectMs: { type: Number, default: 1200 },
  reconnectMaxMs: { type: Number, default: 15000 },
  gameLifetimeHours: { type: Number, default: 24 },
});

// Snapshots are always replaced wholesale, never mutated in place, so they stay
// shallow: a deep ref would hand structuredClone() a reactive Proxy, which the
// structured clone algorithm refuses to copy.
const state = shallowRef(null);
const displayState = shallowRef(null);
const role = ref('connecting');
const seat = ref(null);
const connection = ref('connecting');
const message = ref('Connecting to your game Atom…');
const animating = ref(false);
const dropTarget = ref('');
const copied = ref(false);
let socket;
let reconnectTimer;
let manuallyClosed = false;
let attemptId = 0;
let failures = 0;
let eventQueue = Promise.resolve();

const shareUrl = computed(() => `${window.location.origin}/games/${props.gameId}`);
const statusTitle = computed(() => {
  if (!state.value) return 'Finding the table…';
  if (state.value.status === 'waiting') return 'Waiting for Player 2';
  if (state.value.status === 'expired') return 'This table has disappeared';
  if (state.value.status === 'finished') {
    if (state.value.winner === -1) return 'A beautifully even game';
    return `Player ${state.value.winner + 1} wins`;
  }
  if (role.value === 'observer') return `Player ${state.value.turn + 1} is thinking`;
  return state.value.turn === seat.value ? 'Your turn' : `Player ${state.value.turn + 1}’s turn`;
});

function socketUrl(ticket) {
  const url = new URL(props.atomsEndpoint);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  url.pathname = `${url.pathname.replace(/\/$/, '')}/ws/MancalaGame/${encodeURIComponent(props.gameId)}`;
  // No client_id here: it rides in the ticket as a server-signed claim, and the
  // Worker merges claims over these params, so anything we put here would lose.
  url.search = new URLSearchParams({ channels: 'game', mode: props.mode, ticket });
  return url.toString();
}

// A ticket expires about a minute after it is minted, so every attempt mints a
// fresh one rather than reusing the last, which may have aged out while the
// socket was down.
async function mintTicket() {
  const response = await fetch(`/api/games/${encodeURIComponent(props.gameId)}/ticket`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
  });
  // 419 is an expired session, and no amount of retrying revives one.
  if (response.status === 419) throw Object.assign(new Error('stale page'), { stale: true });
  if (!response.ok) throw new Error(`ticket mint failed: ${response.status}`);
  const { ticket } = await response.json();
  if (typeof ticket !== 'string' || ticket === '') throw new Error('ticket mint returned no ticket');
  return ticket;
}

function retryLater(text) {
  connection.value = 'offline';
  message.value = text;
  if (manuallyClosed || state.value?.status === 'expired') return;
  // Doubling, because each attempt costs a request to Laravel and one from
  // Laravel to the Worker. A flat retry would exhaust the per-session mint
  // limit and turn every later attempt into a 429.
  const delay = Math.min(props.reconnectMs * 2 ** failures, props.reconnectMaxMs);
  failures++;
  window.clearTimeout(reconnectTimer);
  reconnectTimer = window.setTimeout(connect, delay);
}

async function connect() {
  if (!props.atomsEndpoint) {
    connection.value = 'offline';
    message.value = 'ATOMS_ENDPOINT is not configured.';
    return;
  }
  if (manuallyClosed) return;

  // Each attempt claims a number. Anything an older attempt does after this
  // point is ignored, so a superseded mint cannot open a second socket and a
  // superseded socket cannot schedule a reconnect.
  const attempt = ++attemptId;
  connection.value = 'connecting';

  let ticket;
  try {
    ticket = await mintTicket();
  } catch (problem) {
    if (attempt !== attemptId || manuallyClosed) return;
    if (problem.stale) {
      connection.value = 'offline';
      message.value = 'This page has been open too long. Reload to rejoin the table.';
      return;
    }
    retryLater('Could not reach the table. Trying again…');
    return;
  }

  // The component can unmount while the mint is in flight; opening a socket
  // after that would leak one nothing ever closes.
  if (attempt !== attemptId || manuallyClosed) return;

  socket = new WebSocket(socketUrl(ticket));
  socket.addEventListener('open', () => { connection.value = 'live'; failures = 0; });
  socket.addEventListener('message', ({ data }) => {
    const frame = eventFromFrame(JSON.parse(data));
    // Keep the chain alive: a rejected queue would silently drop every later frame.
    eventQueue = eventQueue.then(() => handleFrame(frame)).catch((error) => {
      console.error('Could not apply game frame', frame.kind, error);
      animating.value = false;
      dropTarget.value = '';
    });
  });
  socket.addEventListener('close', () => {
    if (attempt !== attemptId) return;
    connection.value = 'offline';
    retryLater('Connection lost. Rejoining the table…');
  });
  socket.addEventListener('error', () => { message.value = 'The game connection hit a snag.'; });
}

async function handleFrame(frame) {
  if (frame.kind === 'welcome') {
    role.value = frame.role;
    seat.value = frame.seat;
    setState(frame.state);
    message.value = frame.state.status === 'waiting' ? 'Share this page—the game starts when another browser arrives.' : '';
    return;
  }
  if (frame.kind === 'started') {
    setState(frame.state);
    message.value = seat.value === 0 ? 'Player 2 joined. You open.' : 'Both players are here. Player 1 opens.';
    return;
  }
  if (frame.kind === 'moved') {
    await animateMove(frame);
    return;
  }
  if (frame.kind === 'expired') {
    state.value = { ...(state.value ?? {}), status: 'expired', pits: [], stores: [0, 0] };
    displayState.value = structuredClone(state.value);
    message.value = `The ${props.gameLifetimeHours}-hour game window has ended.`;
    socket?.close();
    return;
  }
  if (frame.kind === 'error') {
    if (frame.state) setState(frame.state);
    message.value = errorMessage(frame.code);
  }
}

async function animateMove(event) {
  state.value = structuredClone(event.state);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) {
    displayState.value = structuredClone(event.state);
    announceMove(event);
    return;
  }

  animating.value = true;
  displayState.value = beginAnimatedMove(displayState.value, event.source_pit);
  for (const destination of event.path) {
    dropTarget.value = destination.kind === 'pit' ? `pit-${destination.index}` : `store-${destination.player}`;
    displayState.value = applyDrop(displayState.value, destination);
    await nextTick();
    await new Promise((resolve) => window.setTimeout(resolve, props.stoneDropMs));
  }
  dropTarget.value = '';
  displayState.value = structuredClone(event.state);
  animating.value = false;
  announceMove(event);
}

function announceMove(event) {
  if (event.state.status === 'finished') message.value = statusTitle.value;
  else if (event.capture) message.value = `Player ${event.actor + 1} captured ${event.capture.stones} stones.`;
  else if (event.extra_turn) message.value = `Player ${event.actor + 1} earned another turn.`;
  else message.value = `Player ${event.actor + 1} moved.`;
}

function setState(value) {
  state.value = structuredClone(value);
  displayState.value = structuredClone(value);
}

function makeMove(pit) {
  if (socket?.readyState !== WebSocket.OPEN || animating.value) return;
  socket.send(JSON.stringify({ kind: 'move', pit, revision: state.value.revision }));
}

async function copyLink() {
  await navigator.clipboard.writeText(shareUrl.value);
  copied.value = true;
  window.setTimeout(() => { copied.value = false; }, 1400);
}

function errorMessage(code) {
  return ({
    stale_revision: 'The board moved first; you are caught up now.',
    not_your_turn: 'It is the other player’s turn.',
    pit_not_owned: 'Choose a bowl on your side.',
    pit_empty: 'That bowl is empty.',
    observer_cannot_move: 'Observers can watch, but cannot move stones.',
    game_expired: `This game has reached its ${props.gameLifetimeHours}-hour limit.`,
    game_not_found: 'That game could not be found.',
  })[code] ?? 'That move could not be played.';
}

onMounted(connect);
onBeforeUnmount(() => {
  manuallyClosed = true;
  attemptId++;
  window.clearTimeout(reconnectTimer);
  socket?.close();
});
</script>

<template>
  <div class="site-shell game-page">
    <header class="site-header">
      <AtomsMark />
      <div class="connection-pill" :class="connection"><i></i>{{ connection === 'live' ? 'Live with your Atom' : connection }}</div>
    </header>

    <main class="game-main">
      <section class="game-heading">
        <div>
          <p class="eyebrow">Game {{ gameId.slice(0, 6) }}</p>
          <h1>{{ statusTitle }}</h1>
          <p v-if="role === 'observer'" class="role-note">You have the good seat: watching live.</p>
          <p v-else-if="seat !== null" class="role-note">You are Player {{ seat + 1 }}.</p>
        </div>
        <div class="share-box">
          <span>Invite a second player</span>
          <button @click="copyLink">{{ copied ? 'Copied!' : 'Copy game link' }}</button>
        </div>
      </section>

      <div class="announcement" aria-live="polite">{{ message }}</div>

      <MancalaBoard
        v-if="displayState && displayState.pits?.length === 12"
        :state="displayState"
        :seat="seat"
        :animating="animating"
        :drop-target="dropTarget"
        @move="makeMove"
      />
      <div v-else class="game-placeholder" :class="{ expired: state?.status === 'expired' }">
        <span></span><h2>{{ state?.status === 'expired' ? 'Only the memory remains.' : 'Setting out the stones…' }}</h2>
        <a v-if="state?.status === 'expired'" href="/">Find another table →</a>
      </div>

      <section class="how-to-play">
        <p class="eyebrow">The short version</p>
        <div><span>①</span><p>Choose a bowl on your side.</p></div>
        <div><span>②</span><p>Stones travel counterclockwise.</p></div>
        <div><span>③</span><p>Finish in your store for another turn.</p></div>
        <div><span>④</span><p>Capture across an empty bowl.</p></div>
      </section>
    </main>
  </div>
</template>
