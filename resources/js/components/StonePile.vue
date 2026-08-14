<script setup>
import { computed } from 'vue';

const props = defineProps({ count: { type: Number, required: true } });
const stones = computed(() => Array.from({ length: props.count }, (_, index) => {
  const radius = Math.min(34, 9 + Math.sqrt(index) * 4.8);
  const angle = index * 2.399963;
  return {
    index,
    x: 50 + Math.cos(angle) * radius,
    y: 50 + Math.sin(angle) * radius * 0.72,
    rotation: ((index * 47) % 32) - 16,
  };
}));
</script>

<template>
  <span class="stone-pile" aria-hidden="true">
    <i
      v-for="stone in stones"
      :key="stone.index"
      class="stone"
      :class="`stone-${stone.index % 4}`"
      :style="{ left: `${stone.x}%`, top: `${stone.y}%`, transform: `translate(-50%, -50%) rotate(${stone.rotation}deg)` }"
    ></i>
  </span>
</template>
