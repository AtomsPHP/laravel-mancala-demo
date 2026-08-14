<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import AtomsMark from './AtomsMark.vue';
import MancalaBoard from './MancalaBoard.vue';
import { applyDrop, beginAnimatedMove, eventFromFrame } from '../game-state.js';
import { browserId } from '../identity.js';

const props = defineProps({
  gameId: { type: String, required: true },
  mode: { type: String, default: 'player' },
  atomsEndpoint: { type: String, required: true },
  stoneDropMs: { type: Number, default: 220 },
  reconnectMs: { type: Number, default: 1200 },
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

function socketUrl() {
  const url = new URL(props.atomsEndpoint);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  url.pathname = `${url.pathname.replace(/\/$/, '')}/ws/MancalaGame/${encodeURIComponent(props.gameId)}`;
  url.search = new URLSearchParams({ channels: 'game', client_id: browserId(), mode: props.mode });
  return url.toString();
}

function connect() {
  if (!props.atomsEndpoint) {
    connection.value = 'offline';
    message.value = 'ATOMS_ENDPOINT is not configured.';
    return;
  }

  connection.value = 'connecting';
  socket = new WebSocket(socketUrl());
  socket.addEventListener('open', () => { connection.value = 'live'; });
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
    connection.value = 'offline';
    if (!manuallyClosed && state.value?.status !== 'expired') {
      message.value = 'Connection lost. Rejoining the table…';
      reconnectTimer = window.setTimeout(connect, props.reconnectMs);
    }
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
    message.value = 'The 24-hour game window has ended.';
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
    game_expired: 'This game has reached its 24-hour limit.',
    game_not_found: 'That game could not be found.',
  })[code] ?? 'That move could not be played.';
}

onMounted(connect);
onBeforeUnmount(() => {
  manuallyClosed = true;
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
