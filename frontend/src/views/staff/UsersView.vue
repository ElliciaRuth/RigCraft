<script setup>
/** Admin: staff accounts (create, edit role, deactivate, reset password). */
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { fmtDateTime, initials } from '@/lib/format.js';
import ModalDialog from '@/components/ModalDialog.vue';
import PasswordInput from '@/components/PasswordInput.vue';

const blank = () => ({ id: null, full_name: '', username: '', role: 'technician', is_active: true, password: '' });

const me      = computed(() => session.user || {});
const items   = ref([]);
const loading = ref(true);
const error   = ref('');
const flash   = ref('');
const minLen  = ref(session.rules.min_password_length);

const showForm  = ref(false);
const form      = reactive(blank());
const fields    = ref({});
const formError = ref('');
const busy      = ref(false);
const err = (k) => fields.value[k] || '';

async function load() {
  loading.value = true;
  try {
    const data = await api.get('users');
    items.value = data.items;
    minLen.value = data.min_password_length;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  Object.assign(form, blank());
  fields.value = {}; formError.value = '';
  showForm.value = true;
}
function openEdit(x) {
  Object.assign(form, blank(), { id: x.id, full_name: x.full_name, username: x.username, role: x.role, is_active: x.is_active });
  fields.value = {}; formError.value = '';
  showForm.value = true;
}

async function save() {
  fields.value = {}; formError.value = '';
  busy.value = true;
  try {
    if (form.id) await api.put(`users/${form.id}`, { ...form });
    else await api.post('users', { ...form });
    showForm.value = false;
    flash.value = form.id ? `Saved changes to ${form.full_name}.` : `Created ${form.full_name} (@${form.username.toLowerCase()}).`;
    setTimeout(() => { flash.value = ''; }, 5000);
    await load();
  } catch (e) {
    if (e.fields) fields.value = e.fields;
    formError.value = e.fields ? '' : e.message;
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
          <h1 class="page-title">Users</h1>
          <p class="page-sub mb-0">Staff who can sign in to the console. Technicians log intakes and tests; admins also manage users and delete units.</p>
        </div>
        <button class="btn btn-rc" @click="openCreate"><i class="fa-solid fa-user-plus me-1"></i>Add user</button>
      </div>

      <div v-if="flash" class="alert alert-success py-2"><i class="fa-solid fa-circle-check me-1"></i>{{ flash }}</div>
      <div v-if="error" class="alert alert-danger">{{ error }}</div>

      <section class="rc-card p-0">
        <div class="table-responsive">
          <table class="table table-rc table-hover align-middle mb-0">
            <thead><tr><th class="ps-3">Name</th><th>Role</th><th>Status</th><th class="text-end">Units logged</th><th class="text-end">Tests run</th><th>Last sign-in</th><th class="pe-3"></th></tr></thead>
            <tbody>
              <tr v-if="loading"><td colspan="7" class="text-center py-4"><div class="spinner-border spinner-border-sm text-info"></div></td></tr>
              <tr v-for="x in items" :key="x.id" :style="{ opacity: x.is_active ? 1 : .55 }">
                <td class="ps-3">
                  <div class="d-flex align-items-center gap-2">
                    <span class="avatar-sm">{{ initials(x.full_name) }}</span>
                    <div><div class="fw-semibold">{{ x.full_name }} <span v-if="x.id === me.id" class="tag neutral ms-1">you</span></div>
                      <div class="small text-muted-rc">@{{ x.username }}</div></div>
                  </div>
                </td>
                <td><span class="tag" :class="x.role === 'admin' ? 'pass' : 'neutral'">{{ x.role }}</span></td>
                <td><span class="tag" :class="x.is_active ? 'pass' : 'fail'">{{ x.is_active ? 'Active' : 'Deactivated' }}</span></td>
                <td class="text-end tabular">{{ x.units_logged }}</td>
                <td class="text-end tabular">{{ x.tests_run }}</td>
                <td class="small text-muted-rc text-nowrap">{{ x.last_login_at ? fmtDateTime(x.last_login_at) : 'Never' }}</td>
                <td class="pe-3 text-end"><button class="btn btn-sm btn-outline-secondary" @click="openEdit(x)"><i class="fa-solid fa-pen me-1"></i>Edit</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <ModalDialog :show="showForm" :title="form.id ? 'Edit ' + form.full_name : 'Add user'" :busy="busy" @close="showForm = false">
        <form @submit.prevent="save" novalidate id="user-form">
          <div class="mb-3">
            <label class="form-label" for="u-name">Full name</label>
            <input class="form-control" id="u-name" v-model="form.full_name" :class="{ 'is-invalid': err('full_name') }" maxlength="80" autocomplete="off">
            <div class="invalid-feedback">{{ err('full_name') }}</div>
            <div class="form-text">Shown on stress-test sign-offs and public certificates.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="u-username">Username</label>
            <input class="form-control" id="u-username" v-model="form.username" :disabled="!!form.id" :class="{ 'is-invalid': err('username') }" maxlength="30" autocapitalize="none" autocomplete="off">
            <div class="invalid-feedback">{{ err('username') }}</div>
            <div v-if="form.id" class="form-text">Usernames can't be changed.</div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label" for="u-role">Role</label>
              <select class="form-select" id="u-role" v-model="form.role" :disabled="form.id === me.id">
                <option value="technician">Technician</option>
                <option value="admin">Administrator</option>
              </select>
            </div>
            <div class="col-sm-6 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="u-active" v-model="form.is_active" :disabled="form.id === me.id">
                <label class="form-check-label" for="u-active">Account active</label>
              </div>
            </div>
          </div>
          <div>
            <label class="form-label" for="u-pass">{{ form.id ? 'Reset password' : 'Password' }}</label>
            <PasswordInput class="form-control" id="u-pass" v-model="form.password" :class="{ 'is-invalid': err('password') }" autocomplete="new-password"
                           :placeholder="form.id ? 'Leave blank to keep the current password' : ''" />
            <div class="invalid-feedback">{{ err('password') }}</div>
            <div class="form-text">At least {{ minLen }} characters. Share it with the person privately; they can change it under My account.</div>
          </div>
          <div v-if="formError" class="alert alert-danger py-2 small mt-3 mb-0">{{ formError }}</div>
        </form>
        <template #footer>
          <button class="btn btn-outline-secondary" :disabled="busy" @click="showForm = false">Cancel</button>
          <button class="btn btn-rc" type="submit" form="user-form" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ form.id ? 'Save changes' : 'Create user' }}</button>
        </template>
      </ModalDialog>
    </div>
  </main>
</template>
