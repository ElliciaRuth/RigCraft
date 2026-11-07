/**
 * RigCraft — Vue 3 single-page app (storefront + staff console).
 * Loads who is signed in first, then mounts, so route guards know.
 */
import { createApp } from 'vue';

import 'bootstrap/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/inter/800.css';
import '@fontsource/jetbrains-mono/500.css';
import '@fontsource/jetbrains-mono/600.css';
import './assets/rigcraft.css';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';   // dropdowns, navbar collapse, offcanvas

import App from './App.vue';
import router from './router/index.js';
import { loadSession, useRouterForAuth } from './lib/api.js';

useRouterForAuth(router);

// Install the router only after the session is known: installing it starts the
// first navigation, and its guards need to know who is signed in.
loadSession()
  .catch(() => { /* API down: still show the store; pages report the error */ })
  .finally(() => createApp(App).use(router).mount('#app'));
