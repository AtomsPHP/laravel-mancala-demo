<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AtomsMark from './AtomsMark.vue';
import SourcePanel from './SourcePanel.vue';
import { csrfToken } from '../csrf.js';

const props = defineProps({
  lobbyRefreshMs: { type: Number, default: 15000 },
  gameLifetimeHours: { type: Number, default: 24 },
});
const games = ref([]);
const loadingGames = ref(true);
const creating = ref(false);
const error = ref('');
let refreshTimer;

async function loadGames() {
  try {
    const response = await fetch('/api/games/in-progress', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('The lobby could not be loaded.');
    games.value = (await response.json()).games ?? [];
    error.value = '';
  } catch (problem) {
    error.value = problem.message;
  } finally {
    loadingGames.value = false;
  }
}

async function createGame() {
  creating.value = true;
  error.value = '';
  try {
    // No body: the creator's seat comes from the Laravel session, not the browser.
    const response = await fetch('/api/games', {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    });
    if (!response.ok) throw new Error('A new table could not be created.');
    window.location.assign((await response.json()).url);
  } catch (problem) {
    error.value = problem.message;
    creating.value = false;
  }
}

function score(game) {
  return `${game.stores?.[0] ?? 0}–${game.stores?.[1] ?? 0}`;
}

onMounted(() => {
  loadGames();
  refreshTimer = window.setInterval(loadGames, props.lobbyRefreshMs);
});
onBeforeUnmount(() => window.clearInterval(refreshTimer));
</script>

<template>
  <div class="site-shell home-page">
    <header class="site-header"><AtomsMark /><a class="quiet-link" href="#source">See the code</a></header>

    <main>
      <section class="hero">
        <div class="hero-copy">
          <p class="eyebrow"><span class="live-dot"></span> Persistent PHP, playing live</p>
          <h1>A little game with<br><i>a life of its own.</i></h1>
          <p class="hero-lede">Create a Mancala table, send the link to a friend, and watch one PHP object keep the score—and both browsers—in sync.</p>
          <div class="hero-actions">
            <button class="primary-button" :disabled="creating" @click="createGame">
              <span>{{ creating ? 'Carving your board…' : 'Start a new game' }}</span><span aria-hidden="true">→</span>
            </button>
            <span class="lifetime-note">No account · disappears in {{ gameLifetimeHours }} hours</span>
          </div>
          <p v-if="error" class="error-note" role="alert">{{ error }}</p>
        </div>

        <div class="hero-art" aria-hidden="true">
          <div class="sun-shape"></div>
          <div class="mini-board">
            <div class="mini-store"></div>
            <div class="mini-pits"><i v-for="n in 12" :key="n"><b v-for="s in (n % 4) + 1" :key="s"></b></i></div>
            <div class="mini-store"></div>
          </div>
          <span class="paint-stroke stroke-one"></span><span class="paint-stroke stroke-two"></span>
        </div>
      </section>

      <section class="watch-section" aria-labelledby="watch-title">
        <div class="section-heading">
          <div><p class="eyebrow">Tables in motion</p><h2 id="watch-title">Pull up a chair.</h2></div>
          <button class="text-button" :disabled="loadingGames" @click="loadGames">Shuffle games ↻</button>
        </div>
        <div v-if="loadingGames" class="game-grid" aria-label="Loading live games">
          <div v-for="n in 3" :key="n" class="game-card skeleton"></div>
        </div>
        <div v-else-if="games.length" class="game-grid">
          <a v-for="(game, index) in games" :key="game.id" class="game-card" :href="game.url">
            <span class="card-number">Table {{ String(index + 1).padStart(2, '0') }}</span>
            <div class="card-score"><b>{{ score(game) }}</b><span>Player {{ game.turn + 1 }} to move</span></div>
            <span class="watch-link">Watch live <b>↗</b></span>
          </a>
        </div>
        <div v-else class="empty-lobby">
          <span class="empty-stone"></span>
          <div><h3>The tables are quiet.</h3><p>Start the first game and invite someone in.</p></div>
        </div>
      </section>

      <section id="source" class="source-section" aria-labelledby="source-title">
        <div class="source-intro">
          <p class="eyebrow">The whole trick</p>
          <h2 id="source-title">The game <i>is</i> the object.</h2>
          <p>This is the PHP running inside a SQLite-backed Cloudflare Durable Object. No separate socket server, room coordinator, or state service.</p>
          <ul>
            <li><span>01</span> Every move is one serialized turn.</li>
            <li><span>02</span> SQLite state lives with the game.</li>
            <li><span>03</span> Broadcast reaches every browser live.</li>
          </ul>
        </div>
        <SourcePanel />
      </section>
    </main>

    <footer><AtomsMark /><span>Made for software that feels alive.</span><a href="https://atomsphp.dev">atomsphp.dev ↗</a></footer>
  </div>
</template>
