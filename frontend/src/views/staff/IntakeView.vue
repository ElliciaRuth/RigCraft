<script setup>
/** New component intake: register a unit, optionally with its first stress test, and print its QR label. */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import {
  peso, storage, statusTone, copyText, verifyUrlFor, defaultVerifyBase, QR_BASE_KEY,
  CATEGORIES, BRANDS, STATUS_ICONS,
} from '@/lib/format.js';
import { validateUnit, validateTest, blankTest, testPayload, isBlank } from '@/lib/validation.js';
import QrLabel from '@/components/QrLabel.vue';
import TestFields from '@/components/TestFields.vue';

/** A fresh form. `keep` carries fields over between consecutive intakes. */
const blankForm = (keep = {}) => ({
  model_name: '', category: 'GPU', brand: '', serial_number: '',
  buyout_cost: '', listing_price: '', retail_price: '', notes: '',
  test_completed: true,
  ...blankTest(),
  ...keep,
});

// ---------------- Form ----------------
const form         = reactive(blankForm());
const submitted    = ref(false);   // show client errors only after a submit attempt
const serverErrors = ref({});
const submitting   = ref(false);
const formError    = ref('');
const formEl       = ref(null);

const clientErrors = computed(() => ({
  ...validateUnit(form),
  ...(form.test_completed ? validateTest(form) : {}),
}));
const errorFor = (k) => serverErrors.value[k] || (submitted.value && clientErrors.value[k]) || '';

// Clear a field's server error as soon as it's edited.
watch(() => ({ ...form }), (now, before) => {
  const next = { ...serverErrors.value };
  let changed = false;
  for (const k in now) if (now[k] !== before[k] && next[k]) { delete next[k]; changed = true; }
  if (changed) serverErrors.value = next;
});

const margin = computed(() => {
  if (isBlank(form.buyout_cost) || isBlank(form.listing_price)) return null;
  const buy = Number(form.buyout_cost), sell = Number(form.listing_price);
  if (!Number.isFinite(buy) || !Number.isFinite(sell)) return null;
  const amount = sell - buy;
  const pct = sell > 0 ? (amount / sell) * 100 : 0;
  return { text: `Margin ${peso(amount)} (${pct.toFixed(0)}%)`, positive: amount >= 0 };
});

// "You save" preview vs brand-new SRP, as the shop will show it.
const savings = computed(() => {
  const sell = Number(form.listing_price), srp = Number(form.retail_price);
  if (isBlank(form.retail_price) || isBlank(form.listing_price) || !(srp > sell)) return '';
  return `${peso(srp - sell)} (${Math.round(((srp - sell) / srp) * 100)}% off new)`;
});

// ---------------- QR target ----------------
const defaultBase = defaultVerifyBase();
const verifyBase  = ref(storage.get(QR_BASE_KEY) || defaultBase);
watch(verifyBase, (v) => {
  const val = v.trim();
  if (val && val !== defaultBase) storage.set(QR_BASE_KEY, val); else storage.remove(QR_BASE_KEY);
});
const targetIsLocalhost = computed(() => {
  try { return ['localhost', '127.0.0.1', '[::1]'].includes(new URL(verifyBase.value).hostname); }
  catch { return false; }
});
// On the public website, labels should point at this site. A target saved in this
// browser that names another host (an old LAN address, say) would print labels a
// phone can't open, so say so.
const onPublicSite = !['localhost', '127.0.0.1', '[::1]'].includes(location.hostname) && !/^\d+\.\d+\.\d+\.\d+$/.test(location.hostname);
const targetElsewhere = computed(() => {
  if (!onPublicSite) return false;
  try { return new URL(verifyBase.value).host !== location.host; }
  catch { return true; }
});

// ---------------- Result ----------------
const result     = ref(null);
const copied     = ref('');
const resultUrl  = computed(() => (result.value ? verifyUrlFor(result.value.serial_hash, verifyBase.value.trim()) : ''));
const resultTone = computed(() => (result.value ? statusTone(result.value.status) : ''));
const resultCard = ref(null);

async function copy(text, key) {
  await copyText(text);
  copied.value = key;
  setTimeout(() => { if (copied.value === key) copied.value = ''; }, 1400);
}
const printLabel = () => window.print();

// ---------------- Recent ----------------
const recent = ref([]);
async function loadRecent() {
  try { recent.value = (await api.get('units', { per_page: 6 })).items; }
  catch { /* non-critical */ }
}

// ---------------- Submit ----------------
async function submit() {
  formError.value = '';
  serverErrors.value = {};
  submitted.value = true;
  if (Object.keys(clientErrors.value).length) {
    setTimeout(() => formEl.value?.querySelector('.is-invalid')?.focus());
    return;
  }

  const payload = {
    model_name: form.model_name.trim(),
    category: form.category,
    brand: form.brand.trim(),
    serial_number: form.serial_number.trim(),
    buyout_cost: Number(form.buyout_cost),
    listing_price: Number(form.listing_price),
    retail_price: isBlank(form.retail_price) ? null : Number(form.retail_price),
    notes: form.notes.trim(),
    test_completed: form.test_completed,
    ...(form.test_completed ? testPayload(form) : {}),
  };

  submitting.value = true;
  try {
    const data = await api.post('units', payload);
    result.value = {
      serial_hash: data.serial_hash, status: data.status, reason: data.reason,
      duplicate_of: data.duplicate_of, model_name: payload.model_name, listing_price: payload.listing_price,
    };
    // Fresh form for the next unit; keep the test setup.
    Object.assign(form, blankForm({
      category: form.category, benchmark_tool: form.benchmark_tool, test_duration: form.test_duration,
      ultrasonic_cleaned: form.ultrasonic_cleaned, test_completed: form.test_completed,
    }));
    submitted.value = false;
    loadRecent();
    if (window.innerWidth < 992) setTimeout(() => resultCard.value?.scrollIntoView({ behavior: 'smooth' }));
  } catch (e) {
    if (e.fields) serverErrors.value = e.fields;
    formError.value = e.message;
  } finally {
    submitting.value = false;
  }
}

function resetForm() {
  Object.assign(form, blankForm());
  submitted.value = false;
  serverErrors.value = {};
  formError.value = '';
}

onMounted(loadRecent);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <div class="mb-4">
        <h1 class="page-title">New component intake</h1>
        <p class="page-sub mb-0">Register a unit, record its stress test and print the QR label. Signed off as <strong>{{ session.user?.full_name }}</strong>.</p>
      </div>

      <div class="row g-4">
        <!-- ================= Intake form ================= -->
        <div class="col-lg-7">
          <form ref="formEl" class="rc-card" novalidate @submit.prevent="submit">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="section-label first flex-grow-1 me-3 mb-0"><i class="fa-solid fa-tag"></i>Component</div>
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetForm"><i class="fa-solid fa-rotate-left me-1"></i>Clear</button>
            </div>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label" for="model_name">Component Name</label>
                <input class="form-control" id="model_name" v-model="form.model_name" :class="{ 'is-invalid': errorFor('model_name') }" maxlength="120" placeholder="NVIDIA GeForce RTX 3070 Ti">
                <div class="invalid-feedback">{{ errorFor('model_name') }}</div>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" v-model="form.category">
                  <option v-for="c in CATEGORIES" :key="c">{{ c }}</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="brand">Brand</label>
                <input class="form-control" id="brand" list="brand-list" v-model="form.brand" :class="{ 'is-invalid': errorFor('brand') }" maxlength="60" placeholder="NVIDIA">
                <datalist id="brand-list"><option v-for="b in BRANDS" :key="b" :value="b"></option></datalist>
                <div class="invalid-feedback">{{ errorFor('brand') }}</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="serial_number">Manufacturer Serial Number</label>
                <input class="form-control mono" id="serial_number" v-model="form.serial_number" :class="{ 'is-invalid': errorFor('serial_number') }" maxlength="64" placeholder="2216K0471983" autocomplete="off">
                <div class="invalid-feedback">{{ errorFor('serial_number') }}</div>
              </div>
            </div>

            <div class="section-label"><i class="fa-solid fa-peso-sign"></i>Pricing (PHP)
              <span v-if="margin" class="margin-chip ms-auto" :class="margin.positive ? 'text-emerald' : 'text-red'">{{ margin.text }}</span>
            </div>
            <div class="row g-3">
              <div class="col-sm-4">
                <label class="form-label" for="buyout_cost">Buyout Cost</label>
                <div class="input-group has-validation">
                  <span class="input-group-text">₱</span>
                  <input class="form-control" type="number" id="buyout_cost" v-model.number="form.buyout_cost" :class="{ 'is-invalid': errorFor('buyout_cost') }" min="0" step="0.01" placeholder="13,500.00">
                  <div class="invalid-feedback">{{ errorFor('buyout_cost') }}</div>
                </div>
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="listing_price">Resale Listing Price</label>
                <div class="input-group has-validation">
                  <span class="input-group-text">₱</span>
                  <input class="form-control" type="number" id="listing_price" v-model.number="form.listing_price" :class="{ 'is-invalid': errorFor('listing_price') }" min="0" step="0.01" placeholder="19,999.00">
                  <div class="invalid-feedback">{{ errorFor('listing_price') }}</div>
                </div>
              </div>
              <div class="col-sm-4">
                <label class="form-label" for="retail_price">Brand-new SRP <span class="text-muted-rc">(optional)</span></label>
                <div class="input-group has-validation">
                  <span class="input-group-text">₱</span>
                  <input class="form-control" type="number" id="retail_price" v-model.number="form.retail_price" :class="{ 'is-invalid': errorFor('retail_price') }" min="0" step="0.01" placeholder="32,995.00">
                  <div class="invalid-feedback">{{ errorFor('retail_price') }}</div>
                </div>
                <div v-if="savings" class="form-text text-emerald">Shop shows: save {{ savings }}</div>
              </div>
            </div>

            <div class="section-label"><i class="fa-solid fa-fire"></i>Stress Test</div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" role="switch" id="test_completed" v-model="form.test_completed">
              <label class="form-check-label" for="test_completed">
                Stress test completed now
                <span class="text-muted-rc small d-block">Turn off to queue the unit as <em>Testing</em> and log the results later.</span>
              </label>
            </div>
            <TestFields v-if="form.test_completed" :form="form" :error-for="errorFor" prefix="in-" />

            <div class="section-label"><i class="fa-solid fa-note-sticky"></i>Notes</div>
            <textarea class="form-control" id="notes" rows="2" v-model="form.notes" :class="{ 'is-invalid': errorFor('notes') }" maxlength="1000" placeholder="Condition, accessories, anything the next technician should know (internal)"></textarea>
            <div class="invalid-feedback">{{ errorFor('notes') }}</div>

            <button type="submit" class="btn btn-rc w-100 py-2 mt-4" :disabled="submitting">
              <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="fa-solid fa-qrcode me-1"></i>{{ form.test_completed ? 'Register, certify & generate QR' : 'Register & queue for testing' }}
            </button>

            <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">
              <i class="fa-solid fa-circle-exclamation me-1"></i>{{ formError }}
            </div>
          </form>
        </div>

        <!-- ================= Result / QR panel ================= -->
        <div class="col-lg-5">
          <div class="result-sticky d-flex flex-column gap-3">
            <section ref="resultCard" class="rc-card">
              <div class="rc-card-title mb-3">Generated Label</div>

              <div v-if="!result" class="empty-state">
                <i class="fa-solid fa-qrcode"></i>
                <p class="mt-3 mb-0">Submit an intake to generate the unit's serial hash and a scannable QR label.</p>
              </div>

              <div v-else>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                  <span class="status-pill" :class="resultTone"><i class="fa-solid" :class="STATUS_ICONS[result.status]"></i>{{ result.status }}</span>
                  <button class="btn btn-sm btn-outline-info" type="button" @click="copy(result.serial_hash, 'hash')">
                    <i class="me-1" :class="copied === 'hash' ? 'fa-solid fa-check' : 'fa-regular fa-copy'"></i>{{ copied === 'hash' ? 'Copied' : 'Copy hash' }}
                  </button>
                </div>
                <div class="hash-display mb-1">{{ result.serial_hash }}</div>
                <div v-if="result.reason" class="small mb-2 text-red">{{ result.reason }}</div>
                <div v-if="result.duplicate_of && result.duplicate_of.length" class="alert alert-warning py-2 small mb-2">
                  <i class="fa-solid fa-triangle-exclamation me-1"></i>This S/N is already on file as
                  <template v-for="(h, i) in result.duplicate_of" :key="h">
                    <RouterLink :to="{ name: 'unit', params: { hash: h } }" class="mono">{{ h }}</RouterLink>{{ i < result.duplicate_of.length - 1 ? ', ' : '' }}
                  </template>.
                  Check it isn't a duplicate entry.
                </div>

                <div class="mt-3">
                  <QrLabel :hash="result.serial_hash" :status="result.status" :model="result.model_name" :price="result.listing_price" :url="resultUrl" />
                </div>

                <div class="url-box mt-3">{{ resultUrl }}</div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                  <RouterLink class="btn btn-sm btn-outline-info flex-fill" :to="{ name: 'unit', params: { hash: result.serial_hash } }"><i class="fa-solid fa-box me-1"></i>Unit page</RouterLink>
                  <button class="btn btn-sm btn-outline-info flex-fill" type="button" @click="copy(resultUrl, 'url')">
                    <i class="me-1" :class="copied === 'url' ? 'fa-solid fa-check' : 'fa-regular fa-copy'"></i>{{ copied === 'url' ? 'Copied' : 'Copy link' }}
                  </button>
                  <button class="btn btn-sm btn-outline-light flex-fill" type="button" @click="printLabel"><i class="fa-solid fa-print me-1"></i>Print</button>
                </div>
              </div>
            </section>

            <!-- QR target: phones can't open "localhost" -->
            <section class="rc-card">
              <div class="rc-card-title mb-2">QR Target</div>
              <div v-if="targetIsLocalhost" class="lan-warning p-2 mb-2">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                QR codes point to <strong>this PC only</strong>, which a phone can't reach. Use your PC's LAN address
                (e.g. <span class="mono">http://192.168.1.20:8080/RigCraft/verify</span>) so labels scan correctly.
              </div>
              <div v-else-if="targetElsewhere" class="lan-warning p-2 mb-2">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                QR codes point to <strong>another address</strong>, not this site, so phones may not open them.
                <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="verifyBase = defaultBase">Use {{ defaultBase }}</button>
              </div>
              <label class="form-label small" for="verify-base">Verify page URL encoded in QR codes</label>
              <div class="input-group input-group-sm">
                <input class="form-control mono" id="verify-base" v-model.lazy="verifyBase" spellcheck="false">
                <button class="btn btn-outline-secondary" type="button" title="Reset to this host" @click="verifyBase = defaultBase"><i class="fa-solid fa-rotate-left"></i></button>
              </div>
            </section>

            <!-- Latest intakes -->
            <section class="rc-card">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="rc-card-title">Latest intakes</div>
                <RouterLink :to="{ name: 'inventory' }" class="small">View all</RouterLink>
              </div>
              <ul class="activity" v-if="recent.length">
                <li v-for="it in recent" :key="it.serial_hash">
                  <span class="dot" :class="statusTone(it.status)"></span>
                  <div class="min-w-0 flex-grow-1">
                    <RouterLink :to="{ name: 'unit', params: { hash: it.serial_hash } }" class="d-block text-truncate">{{ it.model_name }}</RouterLink>
                    <span class="small mono text-muted-rc">{{ it.serial_hash }}</span>
                  </div>
                  <span class="tag flex-shrink-0" :class="statusTone(it.status)">{{ it.status }}</span>
                </li>
              </ul>
              <p v-else class="small text-muted-rc mb-0">No units yet.</p>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>
