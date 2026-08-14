<script setup>
import HomeView from './components/HomeView.vue';
import GameView from './components/GameView.vue';

defineProps({
  gameId: { type: String, default: null },
  mode: { type: String, default: 'player' },
  atomsEndpoint: { type: String, default: '' },
  lobbyRefreshMs: { type: Number, default: 15000 },
  stoneDropMs: { type: Number, default: 220 },
  reconnectMs: { type: Number, default: 1200 },
});
</script>

<template>
  <GameView
    v-if="gameId"
    :game-id="gameId"
    :mode="mode"
    :atoms-endpoint="atomsEndpoint"
    :stone-drop-ms="stoneDropMs"
    :reconnect-ms="reconnectMs"
  />
  <HomeView v-else :lobby-refresh-ms="lobbyRefreshMs" />
</template>
