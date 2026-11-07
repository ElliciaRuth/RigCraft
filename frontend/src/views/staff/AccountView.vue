<script setup>
/** My account: profile summary + change own password. */
import { ref, reactive, computed } from 'vue';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { initials } from '@/lib/format.js';
import PasswordInput from '@/components/PasswordInput.vue';

const me      = computed(() => session.user || {});
const role    = computed(() => (me.value.role ? me.value.role[0].toUpperCase() + me.value.role.slice(1) : ''));
const minLen  = computed(() => session.rules.min_password_length);

const form    = reactive({ current: '', new: '', confirm: '' });
const error   = ref('');
const success = ref(false);
const busy    = ref(false);

async function submit() {
  error.value = '';
  success.value = false;
  busy.value = true;
  try {
    await api.post('account/password', { ...form });
    success.value = true;
    Object.assign(form, { current: '', new: '', confirm: '' });
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4" style="max-width: 880px">
      <h1 class="page-title mb-1">My account</h1>
      <p class="page-sub mb-4">Your profile and sign-in details.</p>

      <div class="row g-3">
        <div class="col-md-5">
          <section class="rc-card fill">
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="signoff-avatar">{{ initials(me.full_name) }}</span>
              <div>
                <div class="fw-bold">{{ me.full_name }}</div>
                <div class="small text-muted-rc">@{{ me.username }}</div>
              </div>
            </div>
            <dl class="kv">
              <dt>Role</dt><dd><span class="tag" :class="me.role === 'admin' ? 'pass' : 'neutral'">{{ role }}</span></dd>
              <dt>Signs off as</dt><dd>{{ me.full_name }}</dd>
            </dl>
            <p class="small text-muted-rc mt-3 mb-0">Your name appears on every stress test you log and on the public certificate.
              Ask an administrator to change your name or role.</p>
          </section>
        </div>

        <div class="col-md-7">
          <form class="rc-card fill" novalidate @submit.prevent="submit">
            <div class="rc-card-title mb-3">Change password</div>

            <div v-if="success" class="alert alert-success py-2 small"><i class="fa-solid fa-circle-check me-1"></i>Password updated.</div>
            <div v-else-if="error" class="alert alert-danger py-2 small" role="alert"><i class="fa-solid fa-circle-exclamation me-1"></i>{{ error }}</div>

            <div class="mb-3">
              <label class="form-label" for="current">Current password</label>
              <PasswordInput class="form-control" id="current" v-model="form.current" autocomplete="current-password" required />
            </div>
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label class="form-label" for="new">New password</label>
                <PasswordInput class="form-control" id="new" v-model="form.new" autocomplete="new-password" :minlength="minLen" required />
                <div class="form-text">At least {{ minLen }} characters.</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="confirm">Confirm new password</label>
                <PasswordInput class="form-control" id="confirm" v-model="form.confirm" autocomplete="new-password" required />
              </div>
            </div>
            <button class="btn btn-rc" type="submit" :disabled="busy">
              <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fa-solid fa-key me-1"></i>Update password
            </button>
          </form>
        </div>
      </div>
    </div>
  </main>
</template>
