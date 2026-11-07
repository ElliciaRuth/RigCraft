<script setup>
/** Shopper sign-in for the store (staff use /staff/login). */
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authCall } from '@/lib/api.js';
import { safeNext } from '@/router/index.js';
import PasswordInput from '@/components/PasswordInput.vue';

const route  = useRoute();
const router = useRouter();
const next   = computed(() => safeNext(route.query.next, '/'));

const email    = ref('');
const password = ref('');
const error    = ref('');
const busy     = ref(false);

const notice = computed(() => {
  if (route.query.bye) return 'You have been signed out.';
  if (next.value.startsWith('/checkout')) return 'Sign in or create an account to check out. Your cart is saved.';
  return '';
});

async function submit() {
  error.value = '';
  if (!email.value.trim() || !password.value) { error.value = 'Enter your email and password.'; return; }
  busy.value = true;
  try {
    await authCall('auth/customer/login', { email: email.value.trim(), password: password.value });
    router.replace(next.value);
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
      <form class="rc-card p-4" novalidate @submit.prevent="submit">
        <h1 class="h4 fw-bold mb-1">Sign in</h1>
        <p class="small text-muted-rc mb-3">Welcome back. Sign in to check out and track your orders.</p>

        <div v-if="notice && !error" class="alert alert-info py-2 small"><i class="fa-solid fa-circle-info me-1"></i>{{ notice }}</div>
        <div v-if="error" class="alert alert-danger py-2 small" role="alert"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ error }}</div>

        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
            <input class="form-control" id="email" type="email" v-model="email" autocomplete="email" autocapitalize="none" required autofocus>
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

        <p class="text-center small mt-3 mb-0">
          New to RigCraft? <RouterLink :to="{ name: 'register', query: { next } }">Create an account</RouterLink>
        </p>
      </form>
    </div>
  </main>
</template>
