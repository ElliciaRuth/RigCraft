<script setup>
/** Create a shopper account (signs it in straight away). */
import { ref, reactive, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authCall } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { safeNext } from '@/router/index.js';
import PasswordInput from '@/components/PasswordInput.vue';

const route  = useRoute();
const router = useRouter();
const next   = computed(() => safeNext(route.query.next, '/'));
const minLen = computed(() => session.rules.min_password_length);

const form   = reactive({ full_name: '', email: '', phone: '', password: '', confirm: '' });
const fields = ref({});
const error  = ref('');
const busy   = ref(false);
const err    = (k) => fields.value[k] || '';

async function submit() {
  error.value = '';
  fields.value = {};
  busy.value = true;
  try {
    await authCall('auth/customer/register', { ...form });
    router.replace(next.value);
  } catch (e) {
    fields.value = e.fields || {};
    error.value = e.fields ? 'Please fix the highlighted fields.' : e.message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="auth-wrap">
    <div class="auth-card" style="max-width: 460px">
      <form class="rc-card p-4" novalidate @submit.prevent="submit">
        <h1 class="h4 fw-bold mb-1">Create an account</h1>
        <p class="small text-muted-rc mb-3">You need an account to check out. We use your number only to confirm orders.</p>

        <div v-if="error" class="alert alert-danger py-2 small" role="alert"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ error }}</div>

        <div class="mb-3">
          <label class="form-label" for="full_name">Full name</label>
          <input class="form-control" :class="{ 'is-invalid': err('full_name') }" id="full_name" v-model="form.full_name"
                 autocomplete="name" maxlength="80" required autofocus>
          <div class="invalid-feedback">{{ err('full_name') }}</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" :class="{ 'is-invalid': err('email') }" id="email" type="email" v-model="form.email"
                 autocomplete="email" autocapitalize="none" maxlength="190" required>
          <div class="invalid-feedback">{{ err('email') }}</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="phone">Mobile number</label>
          <input class="form-control" :class="{ 'is-invalid': err('phone') }" id="phone" type="tel" v-model="form.phone"
                 autocomplete="tel" placeholder="0917 123 4567" maxlength="20" required>
          <div class="invalid-feedback">{{ err('phone') }}</div>
        </div>
        <div class="row g-3 mb-4">
          <div class="col-sm-6">
            <label class="form-label" for="password">Password</label>
            <PasswordInput class="form-control" :class="{ 'is-invalid': err('password') }" id="password" v-model="form.password"
                           autocomplete="new-password" :minlength="minLen" required />
            <div v-if="err('password')" class="invalid-feedback">{{ err('password') }}</div>
            <div v-else class="form-text">At least {{ minLen }} characters.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="confirm">Confirm password</label>
            <PasswordInput class="form-control" :class="{ 'is-invalid': err('confirm') }" id="confirm" v-model="form.confirm"
                           autocomplete="new-password" required />
            <div class="invalid-feedback">{{ err('confirm') }}</div>
          </div>
        </div>
        <button type="submit" class="btn btn-rc w-100 py-2" :disabled="busy">
          <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="fa-solid fa-user-plus me-1"></i>Create account
        </button>

        <p class="text-center small mt-3 mb-0">
          Already have an account? <RouterLink :to="{ name: 'signin', query: { next } }">Sign in</RouterLink>
        </p>
      </form>
    </div>
  </main>
</template>
