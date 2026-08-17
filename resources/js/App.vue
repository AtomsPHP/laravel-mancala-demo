<script setup>
import HomeView from './components/HomeView.vue';
import GameView from './components/GameView.vue';

defineProps({
  gameId: { type: String, default: null },
  mode: { type: String, default: 'player' },
  lobbyRefreshMs: { type: Number, default: 15000 },
  gameLifetimeHours: { type: Number, default: 24 },
  stoneDropMs: { type: Number, default: 220 },
  reconnectMs: { type: Number, default: 1200 },
  reconnectMaxMs: { type: Number, default: 15000 },
});
</script>

<template>
  <GameView
    v-if="gameId"
    :game-id="gameId"
    :mode="mode"
    :stone-drop-ms="stoneDropMs"
    :reconnect-ms="reconnectMs"
    :reconnect-max-ms="reconnectMaxMs"
    :game-lifetime-hours="gameLifetimeHours"
  />
  <HomeView v-else :lobby-refresh-ms="lobbyRefreshMs" :game-lifetime-hours="gameLifetimeHours" />
</template>
