<script setup>
/** Staff sign-in. */
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authCall } from '@/lib/api.js';
import { safeNext } from '@/router/index.js';
import PasswordInput from '@/components/PasswordInput.vue';

const route  = useRoute();
const router = useRouter();

const username = ref('');
const password = ref('');
const error    = ref('');
const busy     = ref(false);

const notice = computed(() => {
  if (route.query.bye) return 'You have been signed out.';
  if (route.query.expired) return 'Your session ended. Please sign in again.';
  return '';
});

async function submit() {
  error.value = '';
  if (!username.value.trim() || !password.value) { error.value = 'Enter your username and password.'; return; }
  busy.value = true;
  try {
    await authCall('auth/staff/login', { username: username.value.trim(), password: password.value });
    router.replace(safeNext(route.query.next, '/staff'));
  } catch (e) {
    error.value = e.message;
    password.value = '';
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="auth-wrap">
    <div class="auth-card">
      <div class="text-center mb-4">
        <RouterLink class="rc-logo fs-3" :to="{ name: 'home' }"><i class="fa-solid fa-microchip me-1"></i>Rig<span>Craft</span></RouterLink>
        <div class="text-muted-rc small mt-1">Technician &amp; admin console</div>
      </div>

      <form class="rc-card p-4" novalidate @submit.prevent="submit">
        <h1 class="h5 fw-bold mb-3">Sign in</h1>

        <div v-if="notice && !error" class="alert alert-info py-2 small"><i class="fa-solid fa-circle-info me-1"></i>{{ notice }}</div>
        <div v-if="error" class="alert alert-danger py-2 small" role="alert"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ error }}</div>

        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
            <input class="form-control" id="username" v-model="username" autocomplete="username" autocapitalize="none" required autofocus>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Password</label>
          <div class="input-group flex-nowrap">
            <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
            <PasswordInput id="password" class="form-control" v-model="password" autocomplete="current-password" required />
          </div>
        </div>
        <button type="submit" class="btn btn-rc w-100 py-2" :disabled="busy">
          <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="fa-solid fa-right-to-bracket me-1"></i>Sign in
        </button>
      </form>

      <p class="text-center small mt-3 mb-0">
        <RouterLink :to="{ name: 'home' }" class="text-muted-rc text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Back to the RigCraft store</RouterLink>
      </p>
    </div>
  </main>
</template>
