<script setup>
import StonePile from './StonePile.vue';
import { BOTTOM_PITS, TOP_PITS, canPlay } from '../game-state.js';

const props = defineProps({
  state: { type: Object, required: true },
  seat: { type: Number, default: null },
  animating: { type: Boolean, default: false },
  dropTarget: { type: String, default: '' },
});
const emit = defineEmits(['move']);

function pitLabel(pit) {
  const owner = pit < 6 ? 1 : 2;
  return `Player ${owner} pit ${pit < 6 ? pit + 1 : pit - 5}, ${props.state.pits[pit]} stones`;
}
</script>

<template>
  <div class="board-wrap">
    <div class="player-ribbon player-two" :class="{ current: state.turn === 1 }">
      <span>Player 2</span><b>{{ state.stores[1] }}</b>
    </div>
    <div class="mancala-board" :class="{ animating }">
      <div class="store store-two" :class="{ dropping: dropTarget === 'store-1' }">
        <StonePile :count="state.stores[1]" /><strong>{{ state.stores[1] }}</strong><span>Store</span>
      </div>
      <div class="pit-grid">
        <button
          v-for="pit in TOP_PITS"
          :key="pit"
          class="pit"
          :class="{ playable: canPlay(state, seat, pit, animating), dropping: dropTarget === `pit-${pit}` }"
          :disabled="!canPlay(state, seat, pit, animating)"
          :aria-label="pitLabel(pit)"
          @click="emit('move', pit)"
        ><StonePile :count="state.pits[pit]" /><strong>{{ state.pits[pit] }}</strong></button>
        <button
          v-for="pit in BOTTOM_PITS"
          :key="pit"
          class="pit"
          :class="{ playable: canPlay(state, seat, pit, animating), dropping: dropTarget === `pit-${pit}` }"
          :disabled="!canPlay(state, seat, pit, animating)"
          :aria-label="pitLabel(pit)"
          @click="emit('move', pit)"
        ><StonePile :count="state.pits[pit]" /><strong>{{ state.pits[pit] }}</strong></button>
      </div>
      <div class="store store-one" :class="{ dropping: dropTarget === 'store-0' }">
        <StonePile :count="state.stores[0]" /><strong>{{ state.stores[0] }}</strong><span>Store</span>
      </div>
    </div>
    <div class="player-ribbon player-one" :class="{ current: state.turn === 0 }">
      <span>Player 1</span><b>{{ state.stores[0] }}</b>
    </div>
  </div>
</template>
