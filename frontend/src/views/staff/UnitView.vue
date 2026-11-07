<script setup>
/** Unit detail: details, test history, QR label and every action on a unit (edit, re-test, sell / undo, delete). */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/lib/api.js';
import { session, isAdmin } from '@/lib/session.js';
import { peso, fmtDate, fmtDateTime, statusTone, copyText, verifyUrlFor, todayIso, CATEGORIES, STATUS_ICONS } from '@/lib/format.js';
import { validateUnit, validateTest, blankTest, testPayload, isBlank } from '@/lib/validation.js';
import ModalDialog from '@/components/ModalDialog.vue';
import QrLabel from '@/components/QrLabel.vue';
import TestFields from '@/components/TestFields.vue';

const route  = useRoute();
const router = useRouter();
const hash   = String(route.params.hash || '').trim().toUpperCase();
const me     = computed(() => session.user || {});
const path   = `units/${encodeURIComponent(hash)}`;

const state     = ref('loading');
const loadError = ref('');
const u    = ref(null);    // component
const logs = ref([]);
const w    = ref(null);    // warranty
const ceiling = ref(session.rules.temp_ceiling_celsius);

const busy       = ref(false);
const flash      = ref(null);
const modal      = ref('');          // '' | retest | sell | unsell | delete
const modalError = ref('');
const copied     = ref(false);

const tone      = computed(() => statusTone(u.value?.status));
const publicUrl = computed(() => verifyUrlFor(hash));

function showFlash(text, type = 'success') {
  flash.value = { text, type };
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => { if (flash.value?.text === text) flash.value = null; }, 5000);
}

async function load() {
  try {
    const data = await api.get(path);
    u.value = data.component;
    logs.value = data.logs;
    w.value = data.warranty;
    ceiling.value = data.rules.temp_ceiling_celsius;
    document.title = `${data.component.model_name} · RigCraft`;
    state.value = 'ready';
  } catch (e) {
    loadError.value = e.message;
    state.value = 'error';
  }
}

/** Run an API action with shared busy/error handling. */
async function run(fn, { onFields } = {}) {
  busy.value = true;
  modalError.value = '';
  try {
    await fn();
    return true;
  } catch (e) {
    if (e.fields && onFields) onFields(e.fields);
    modalError.value = e.message;
    return false;
  } finally {
    busy.value = false;
  }
}

// ---------------- Edit details ----------------
const editing    = ref(false);
const ef         = reactive({});
const editTried  = ref(false);
const editServer = ref({});
const editError  = ref('');
const editClient = computed(() => validateUnit(ef, { withWarranty: true }));
const editErr    = (k) => editServer.value[k] || (editTried.value && editClient.value[k]) || '';

function startEdit() {
  const c = u.value;
  Object.assign(ef, {
    model_name: c.model_name, category: c.category, brand: c.brand, serial_number: c.serial_number,
    buyout_cost: c.buyout_cost, listing_price: c.listing_price, retail_price: c.retail_price ?? '',
    warranty_months: c.warranty_months, notes: c.notes || '',
  });
  editTried.value = false; editServer.value = {}; editError.value = '';
  editing.value = true;
}

async function saveEdit() {
  editTried.value = true; editServer.value = {}; editError.value = '';
  if (Object.keys(editClient.value).length) return;
  busy.value = true;
  try {
    await api.put(path, {
      ...ef,
      buyout_cost: Number(ef.buyout_cost),
      listing_price: Number(ef.listing_price),
      retail_price: isBlank(ef.retail_price) ? null : Number(ef.retail_price),
      warranty_months: Number(ef.warranty_months),
    });
    editing.value = false;
    await load();
    showFlash('Details saved.');
  } catch (e) {
    if (e.fields) editServer.value = e.fields;
    editError.value = e.message;
  } finally {
    busy.value = false;
  }
}

// ---------------- Re-test ----------------
const rt        = reactive(blankTest());
const rtTried   = ref(false);
const rtServer  = ref({});
const rtClient  = computed(() => validateTest(rt));
const rtErr     = (k) => rtServer.value[k] || (rtTried.value && rtClient.value[k]) || '';

function openRetest() {
  const last = logs.value[0];
  Object.assign(rt, blankTest(), last ? { benchmark_tool: last.benchmark_tool, test_duration: last.test_duration_mins } : {});
  rtTried.value = false; rtServer.value = {}; modalError.value = '';
  modal.value = 'retest';
}

async function saveRetest() {
  rtTried.value = true; rtServer.value = {};
  if (Object.keys(rtClient.value).length) return;
  let res;
  const ok = await run(async () => { res = await api.post(`${path}/retest`, testPayload(rt)); },
                       { onFields: (f) => { rtServer.value = f; } });
  if (!ok) return;
  modal.value = '';
  await load();
  showFlash(res.stress_status === 'PASSED'
    ? 'Stress test PASSED — unit is now Certified.'
    : `Stress test FAILED — unit Rejected. ${res.reason || ''}`, res.stress_status === 'PASSED' ? 'success' : 'warning');
}

async function startTesting() {
  const ok = await run(() => api.post(`${path}/status`, { status: 'Testing' }));
  if (ok) { await load(); showFlash('Unit moved to Testing.'); }
  else showFlash(modalError.value, 'danger');
}

// ---------------- Sell / unsell ----------------
const today      = todayIso();
const sell       = reactive({ sold_price: '', sold_at: today });
const sellServer = ref({});
const sellErr    = (k) => sellServer.value[k] || '';
const sellProfit = computed(() => (isBlank(sell.sold_price) ? null : Number(sell.sold_price) - u.value.buyout_cost));

function openSell() {
  Object.assign(sell, { sold_price: u.value.listing_price || '', sold_at: today });
  sellServer.value = {}; modalError.value = '';
  modal.value = 'sell';
}

async function saveSell() {
  sellServer.value = {};
  const p = Number(sell.sold_price);
  if (isBlank(sell.sold_price) || !Number.isFinite(p) || p < 0 || p > session.rules.max_price) {
    sellServer.value = { sold_price: 'Enter the sale price.' };
    return;
  }
  const ok = await run(() => api.post(`${path}/sell`, { sold_price: p, sold_at: sell.sold_at }),
                       { onFields: (f) => { sellServer.value = f; } });
  if (!ok) return;
  modal.value = '';
  await load();
  showFlash(`Sale recorded: ${peso(p)}.`);
}

async function doUnsell() {
  const ok = await run(() => api.post(`${path}/unsell`));
  if (!ok) return;
  modal.value = '';
  await load();
  showFlash('Sale undone. Unit is back in certified stock.');
}

// ---------------- Delete ----------------
const deleteConfirm = ref('');
function openDelete() { deleteConfirm.value = ''; modalError.value = ''; modal.value = 'delete'; }
async function doDelete() {
  const ok = await run(() => api.del(path));
  if (ok) router.push({ name: 'inventory' });
}

// ---------------- Label ----------------
const print = () => window.print();
async function copyLink() {
  await copyText(publicUrl.value);
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 1400);
}

onMounted(async () => {
  await load();
  if (route.query.retest && u.value && u.value.status !== 'Sold') openRetest();
});
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
          <li class="breadcrumb-item"><RouterLink :to="{ name: 'inventory' }">Inventory</RouterLink></li>
          <li class="breadcrumb-item active mono" aria-current="page">{{ hash || 'Unit' }}</li>
        </ol>
      </nav>

      <div v-if="state === 'loading'" class="empty-state"><div class="spinner-border text-info"></div></div>

      <div v-else-if="state === 'error'" class="state-box">
        <div class="state-icon mb-3 text-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h1 class="h4 fw-bold">Unit not found</h1>
        <p class="text-muted-rc">{{ loadError }}</p>
        <RouterLink :to="{ name: 'inventory' }" class="btn btn-outline-info btn-sm">Back to inventory</RouterLink>
      </div>

      <template v-else>
        <!-- Flash -->
        <div v-if="flash" class="alert py-2" :class="'alert-' + flash.type" role="status">
          <i class="fa-solid me-1" :class="flash.type === 'success' ? 'fa-circle-check' : 'fa-circle-info'"></i>{{ flash.text }}
        </div>

        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <span class="status-pill" :class="tone"><i class="fa-solid" :class="STATUS_ICONS[u.status]"></i>{{ u.status }}</span>
              <span class="mono text-cyan">{{ u.serial_hash }}</span>
            </div>
            <h1 class="page-title">{{ u.model_name }}</h1>
            <div class="page-sub">{{ u.brand }} · {{ u.category }} · taken in {{ fmtDate(u.intake_date) }}{{ u.created_by_name ? ' by ' + u.created_by_name : '' }}</div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button v-if="u.status === 'Intake'" class="btn btn-outline-light" :disabled="busy" @click="startTesting"><i class="fa-solid fa-play me-1"></i>Start testing</button>
            <button v-if="u.status !== 'Sold'" class="btn" :class="['Intake','Testing'].includes(u.status) ? 'btn-rc' : 'btn-outline-info'" @click="openRetest">
              <i class="fa-solid fa-fire me-1"></i>{{ logs.length ? 'Log re-test' : 'Log stress test' }}
            </button>
            <button v-if="u.status === 'Certified'" class="btn btn-emerald" @click="openSell"><i class="fa-solid fa-receipt me-1"></i>Mark as sold</button>
            <button v-if="u.status === 'Sold'" class="btn btn-outline-warning" @click="modalError = ''; modal = 'unsell'"><i class="fa-solid fa-rotate-left me-1"></i>Undo sale</button>
          </div>
        </div>

        <div class="row g-3">
          <!-- ============ Left column ============ -->
          <div class="col-lg-8 d-flex flex-column gap-3">

            <!-- Details (view / edit) -->
            <section class="rc-card">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="rc-card-title">Details</div>
                <button v-if="!editing" class="btn btn-sm btn-outline-secondary" @click="startEdit"><i class="fa-solid fa-pen me-1"></i>Edit</button>
              </div>

              <dl v-if="!editing" class="kv">
                <dt>Model</dt><dd>{{ u.model_name }}</dd>
                <dt>Brand / category</dt><dd>{{ u.brand }} · {{ u.category }}</dd>
                <dt>Manufacturer S/N</dt><dd class="mono">{{ u.serial_number }}</dd>
                <dt>Buyout cost</dt><dd class="tabular">{{ peso(u.buyout_cost) }}</dd>
                <dt>Listing price</dt><dd class="tabular">{{ u.listing_price > 0 ? peso(u.listing_price) : 'Not listed' }}
                  <span v-if="u.listing_price > 0 && u.status !== 'Sold'" class="small ms-2" :class="u.listing_price - u.buyout_cost >= 0 ? 'text-emerald' : 'text-red'">
                    ({{ peso(u.listing_price - u.buyout_cost) }} margin)</span></dd>
                <dt>Brand-new SRP</dt><dd class="tabular">{{ u.retail_price ? peso(u.retail_price) : 'Not set' }}
                  <span v-if="u.retail_price > u.listing_price && u.listing_price > 0" class="small ms-2 text-emerald">
                    (shop shows {{ Math.round((u.retail_price - u.listing_price) / u.retail_price * 100) }}% off new)</span></dd>
                <dt>Warranty</dt><dd>{{ u.warranty_months }} months</dd>
                <dt>Notes</dt><dd :class="u.notes ? '' : 'text-muted-rc'" style="white-space: pre-line">{{ u.notes || 'None' }}</dd>
                <dt>Last updated</dt><dd class="text-muted-rc">{{ fmtDateTime(u.updated_at) }}</dd>
              </dl>

              <form v-else @submit.prevent="saveEdit" novalidate>
                <div class="row g-3">
                  <div class="col-md-8">
                    <label class="form-label" for="e-model">Component Name</label>
                    <input class="form-control" id="e-model" v-model="ef.model_name" :class="{ 'is-invalid': editErr('model_name') }" maxlength="120">
                    <div class="invalid-feedback">{{ editErr('model_name') }}</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="e-cat">Category</label>
                    <select class="form-select" id="e-cat" v-model="ef.category"><option v-for="c in CATEGORIES" :key="c">{{ c }}</option></select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="e-brand">Brand</label>
                    <input class="form-control" id="e-brand" v-model="ef.brand" :class="{ 'is-invalid': editErr('brand') }" maxlength="60">
                    <div class="invalid-feedback">{{ editErr('brand') }}</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="e-sn">Manufacturer S/N</label>
                    <input class="form-control mono" id="e-sn" v-model="ef.serial_number" :class="{ 'is-invalid': editErr('serial_number') }" maxlength="64">
                    <div class="invalid-feedback">{{ editErr('serial_number') }}</div>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label" for="e-buy">Buyout cost</label>
                    <div class="input-group has-validation"><span class="input-group-text">₱</span>
                      <input class="form-control" type="number" id="e-buy" v-model.number="ef.buyout_cost" :class="{ 'is-invalid': editErr('buyout_cost') }" min="0" step="0.01">
                      <div class="invalid-feedback">{{ editErr('buyout_cost') }}</div></div>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label" for="e-list">Listing price</label>
                    <div class="input-group has-validation"><span class="input-group-text">₱</span>
                      <input class="form-control" type="number" id="e-list" v-model.number="ef.listing_price" :class="{ 'is-invalid': editErr('listing_price') }" min="0" step="0.01">
                      <div class="invalid-feedback">{{ editErr('listing_price') }}</div></div>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label" for="e-srp">Brand-new SRP</label>
                    <div class="input-group has-validation"><span class="input-group-text">₱</span>
                      <input class="form-control" type="number" id="e-srp" v-model.number="ef.retail_price" :class="{ 'is-invalid': editErr('retail_price') }" min="0" step="0.01" placeholder="Optional">
                      <div class="invalid-feedback">{{ editErr('retail_price') }}</div></div>
                  </div>
                  <div class="col-sm-4">
                    <label class="form-label" for="e-war">Warranty (months)</label>
                    <input class="form-control" type="number" id="e-war" v-model.number="ef.warranty_months" :class="{ 'is-invalid': editErr('warranty_months') }" min="0" :max="session.rules.max_warranty" step="1">
                    <div class="invalid-feedback">{{ editErr('warranty_months') }}</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="e-notes">Notes <span class="text-muted-rc">(internal)</span></label>
                    <textarea class="form-control" id="e-notes" rows="3" v-model="ef.notes" :class="{ 'is-invalid': editErr('notes') }" maxlength="1000"></textarea>
                    <div class="invalid-feedback">{{ editErr('notes') }}</div>
                  </div>
                </div>
                <div v-if="editError" class="alert alert-danger py-2 small mt-3 mb-0">{{ editError }}</div>
                <div class="d-flex gap-2 justify-content-end mt-3">
                  <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="editing = false">Cancel</button>
                  <button type="submit" class="btn btn-rc" :disabled="busy"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Save changes</button>
                </div>
              </form>
            </section>

            <!-- Sale -->
            <section v-if="u.status === 'Sold'" class="rc-card">
              <div class="rc-card-title mb-3">Sale</div>
              <div class="row g-3 text-center">
                <div class="col-4"><div class="kpi-label">Sold for</div><div class="kpi-value fs-5">{{ peso(u.sold_price) }}</div></div>
                <div class="col-4"><div class="kpi-label">Profit</div><div class="kpi-value fs-5" :class="u.sold_price - u.buyout_cost >= 0 ? 'text-emerald' : 'text-red'">{{ peso(u.sold_price - u.buyout_cost) }}</div></div>
                <div class="col-4"><div class="kpi-label">Sold on</div><div class="kpi-value fs-5">{{ fmtDate(u.sold_at) }}</div></div>
              </div>
            </section>

            <!-- Test history -->
            <section class="rc-card p-0">
              <div class="d-flex justify-content-between align-items-center p-3 pb-2">
                <div class="rc-card-title">Stress test history</div>
                <span class="small text-muted-rc">{{ logs.length }} run{{ logs.length === 1 ? '' : 's' }} · ceiling {{ ceiling.toFixed(1) }} °C</span>
              </div>
              <div v-if="!logs.length" class="empty-state pt-2">
                <i class="fa-solid fa-fire"></i>
                <p class="mt-2 mb-2">No stress test logged yet.</p>
                <button class="btn btn-sm btn-rc" @click="openRetest">Log stress test</button>
              </div>
              <div v-else class="table-responsive">
                <table class="table table-rc align-middle mb-0">
                  <thead><tr><th class="ps-3">Date</th><th>Technician</th><th>Tool</th><th class="text-end">Peak</th><th class="text-end">Fan</th><th class="text-end">Mem err</th><th>Prep</th><th class="pe-3">Result</th></tr></thead>
                  <tbody>
                    <tr v-for="(l, i) in logs" :key="l.id">
                      <td class="ps-3 small">{{ fmtDate(l.test_date) }}<span v-if="i === 0" class="tag neutral ms-1">latest</span></td>
                      <td class="text-nowrap">{{ l.tested_by }}</td>
                      <td class="text-nowrap small text-muted-rc">{{ l.benchmark_tool }} · {{ l.test_duration_mins }}m</td>
                      <td class="text-end tabular text-nowrap" :class="l.peak_temp_celsius > ceiling ? 'text-red' : 'text-emerald'">{{ l.peak_temp_celsius.toFixed(1) }} °C</td>
                      <td class="text-end tabular">{{ l.fan_speed_pct }}%</td>
                      <td class="text-end tabular" :class="l.memory_error_count ? 'text-red' : ''">{{ l.memory_error_count }}</td>
                      <td class="text-nowrap text-muted-rc">
                        <i v-if="l.thermal_paste_replaced" class="fa-solid fa-droplet me-2" :title="'Re-pasted: ' + l.paste_brand"></i>
                        <i v-if="l.ultrasonic_cleaned" class="fa-solid fa-water" title="Ultrasonic PCB cleaning"></i>
                        <span v-if="!l.thermal_paste_replaced && !l.ultrasonic_cleaned">—</span>
                      </td>
                      <td class="pe-3"><span class="tag" :class="l.stress_status === 'PASSED' ? 'pass' : 'fail'">{{ l.stress_status }}</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          <!-- ============ Right column ============ -->
          <div class="col-lg-4 d-flex flex-column gap-3">
            <section class="rc-card">
              <div class="rc-card-title mb-3">QR label</div>
              <QrLabel :hash="u.serial_hash" :status="u.status" :model="u.model_name" :price="u.listing_price" :url="publicUrl" />
              <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-sm btn-outline-light flex-fill" @click="print"><i class="fa-solid fa-print me-1"></i>Print</button>
                <a class="btn btn-sm btn-outline-info flex-fill" :href="publicUrl" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Public page</a>
                <button class="btn btn-sm btn-outline-info flex-fill" @click="copyLink"><i class="me-1" :class="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'"></i>{{ copied ? 'Copied' : 'Copy link' }}</button>
              </div>
            </section>

            <section class="rc-card">
              <div class="rc-card-title mb-2">Warranty</div>
              <div v-if="w.active" class="text-emerald fw-semibold"><i class="fa-solid fa-shield-halved me-1"></i>Active until {{ fmtDate(w.expires_on) }}</div>
              <div v-else-if="w.expires_on" class="fw-semibold">Expired {{ fmtDate(w.expires_on) }}</div>
              <div v-else class="text-muted-rc">{{ u.status === 'Rejected' ? 'Void — unit rejected' : 'Starts when the unit is certified' }}</div>
              <div v-if="w.expires_on" class="small text-muted-rc mt-1">{{ w.months }} months from the {{ w.from_sale ? 'sale' : 'certification' }} date ({{ fmtDate(w.starts_on) }}).</div>
            </section>

            <section v-if="isAdmin" class="rc-card" style="border-color: rgba(239,68,68,.4)">
              <div class="rc-card-title mb-2 text-red">Danger zone</div>
              <p class="small text-muted-rc">Deleting removes this unit and its whole test history. Its QR label will show as unregistered.</p>
              <button class="btn btn-sm btn-outline-danger" @click="openDelete"><i class="fa-solid fa-trash me-1"></i>Delete unit</button>
            </section>
          </div>
        </div>

        <!-- ============ Modals ============ -->
        <ModalDialog :show="modal === 'retest'" :title="logs.length ? 'Log re-test' : 'Log stress test'" size="lg" :busy="busy" @close="modal = ''">
          <p class="small text-muted-rc">Results are signed off as <strong>{{ me.full_name }}</strong>. The unit is certified or rejected automatically.</p>
          <TestFields :form="rt" :error-for="rtErr" prefix="rt-" />
          <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
          <template #footer>
            <button class="btn btn-outline-secondary" :disabled="busy" @click="modal = ''">Cancel</button>
            <button class="btn btn-rc" :disabled="busy" @click="saveRetest"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Save result</button>
          </template>
        </ModalDialog>

        <ModalDialog :show="modal === 'sell'" title="Mark as sold" :busy="busy" @close="modal = ''">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="s-price">Sale price</label>
              <div class="input-group has-validation"><span class="input-group-text">₱</span>
                <input class="form-control" type="number" id="s-price" v-model.number="sell.sold_price" :class="{ 'is-invalid': sellErr('sold_price') }" min="0" step="0.01">
                <div class="invalid-feedback">{{ sellErr('sold_price') }}</div></div>
              <div class="form-text">Listed at {{ peso(u.listing_price) }}</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="s-date">Sale date</label>
              <input class="form-control" type="date" id="s-date" v-model="sell.sold_at" :max="today" :min="u.intake_date" :class="{ 'is-invalid': sellErr('sold_at') }">
              <div class="invalid-feedback">{{ sellErr('sold_at') }}</div>
            </div>
          </div>
          <div v-if="sellProfit !== null" class="verdict mt-3" :class="sellProfit >= 0 ? 'pass' : 'fail'">
            Profit on this sale: <strong>{{ peso(sellProfit) }}</strong>. The buyer's {{ u.warranty_months }}-month warranty starts on the sale date.
          </div>
          <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
          <template #footer>
            <button class="btn btn-outline-secondary" :disabled="busy" @click="modal = ''">Cancel</button>
            <button class="btn btn-emerald" :disabled="busy" @click="saveSell"><span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Record sale</button>
          </template>
        </ModalDialog>

        <ModalDialog :show="modal === 'unsell'" title="Undo sale?" size="sm" :busy="busy" @close="modal = ''">
          <p class="mb-0">The unit goes back to <strong>Certified</strong> stock and the sale price and date are cleared.</p>
          <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
          <template #footer>
            <button class="btn btn-outline-secondary" :disabled="busy" @click="modal = ''">Keep sale</button>
            <button class="btn btn-warning" :disabled="busy" @click="doUnsell">Undo sale</button>
          </template>
        </ModalDialog>

        <ModalDialog :show="modal === 'delete'" title="Delete unit" :busy="busy" @close="modal = ''">
          <p>This permanently deletes <strong>{{ u.model_name }}</strong> and {{ logs.length }} test record{{ logs.length === 1 ? '' : 's' }}. This can't be undone.</p>
          <label class="form-label small" for="del-confirm">Type <span class="mono text-cyan">{{ u.serial_hash }}</span> to confirm</label>
          <input class="form-control mono" id="del-confirm" v-model="deleteConfirm" autocomplete="off">
          <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
          <template #footer>
            <button class="btn btn-outline-secondary" :disabled="busy" @click="modal = ''">Cancel</button>
            <button class="btn btn-danger" :disabled="busy || deleteConfirm.trim().toUpperCase() !== u.serial_hash" @click="doDelete">Delete permanently</button>
          </template>
        </ModalDialog>
      </template>
    </div>
  </main>
</template>
