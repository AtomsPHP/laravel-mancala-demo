<script setup>
import { computed, ref } from 'vue';
import Prism from 'prismjs';
import 'prismjs/components/prism-markup-templating.js';
import 'prismjs/components/prism-php.js';
import mancalaSource from '../../../app/Atoms/MancalaGame.php?raw';
import directorySource from '../../../app/Atoms/GameDirectory.php?raw';

const files = [
  { name: 'MancalaGame.php', source: mancalaSource },
  { name: 'GameDirectory.php', source: directorySource },
];
const selected = ref(0);
const expanded = ref(false);
const copied = ref(false);
const highlighted = computed(() => Prism.highlight(files[selected.value].source, Prism.languages.php, 'php'));

async function copySource() {
  await navigator.clipboard.writeText(files[selected.value].source);
  copied.value = true;
  window.setTimeout(() => { copied.value = false; }, 1400);
}
</script>

<template>
  <div class="source-panel">
    <div class="source-toolbar">
      <div class="source-tabs" role="tablist" aria-label="Atom source files">
        <button
          v-for="(file, index) in files"
          :key="file.name"
          role="tab"
          :aria-selected="selected === index"
          @click="selected = index"
        >{{ file.name }}</button>
      </div>
      <button class="copy-button" @click="copySource">{{ copied ? 'Copied!' : 'Copy' }}</button>
    </div>
    <div class="code-window" :class="{ expanded }">
      <pre aria-label="Actual Atom PHP source"><code class="language-php" v-html="highlighted"></code></pre>
      <div v-if="!expanded" class="code-fade"></div>
    </div>
    <button class="expand-code" @click="expanded = !expanded">{{ expanded ? 'Collapse source ↑' : 'Read the full class ↓' }}</button>
  </div>
</template>
