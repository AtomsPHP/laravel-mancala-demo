import { createApp } from 'vue';
import App from './App.vue';

createApp(App, window.__MANCALA__ ?? {}).mount('#app');
