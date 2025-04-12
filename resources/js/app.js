// resources/js/app.js
import './bootstrap.js';
import { createApp } from 'vue';
import AiAssistant from './components/AiAssistant.vue';

const app = createApp({});
app.component('ai-assistant', AiAssistant);
app.mount('#app');
