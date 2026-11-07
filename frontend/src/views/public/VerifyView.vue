<script setup>
/**
 * Public verification page — the landing page for a unit's QR label.
 *   /verify               → chooser (search box, listed units, demo link)
 *   /verify?hash=RC-…     → the unit's test report: identity, metrics, stress
 *                           chart, refurbishment checklist, warranty
 *   …&compare=RC-…        → side-by-side with another unit of the same category
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Chart from 'chart.js/auto';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { cart } from '@/lib/cart.js';
import { fmtDate, peso, pesoWhole, statusTone, categoryIcon, DEMO_HASH } from '@/lib/format.js';
import { productPhoto } from '@/lib/photos.js';
import ProductZoom from '@/components/ProductZoom.vue';
import UnitSearch from '@/components/UnitSearch.vue';

const STATUS_META = {
  Certified: { icon: 'fa-circle-check',   label: 'Certified · Stress Test Passed' },
  Sold:      { icon: 'fa-circle-check',   label: 'Certified · Sold' },
  Rejected:  { icon: 'fa-circle-xmark',   label: 'Rejected · Failed Stress Test' },
  Testing:   { icon: 'fa-hourglass-half', label: 'In Testing' },
  Intake:    { icon: 'fa-inbox',          label: 'Awaiting Test' },
};
const COMPARE_COLOR = '#F59E0B';

// ---------------------------------------------------------------------
// Simulated stress curve. The DB stores the peak reading, not per-minute
// samples, so we rebuild a realistic warm-up curve that tops out at the
// logged peak. Seeded by the hash so a unit always shows the same curve.
// ---------------------------------------------------------------------
function seededRng(str) {
  let s = 0;
  for (const ch of str) s = (Math.imul(31, s) + ch.charCodeAt(0)) | 0;
  return () => {                                   // mulberry32
    s = (s + 0x6D2B79F5) | 0;
    let t = Math.imul(s ^ (s >>> 15), 1 | s);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
function simulateRun(peak, minutes, seed) {
  const rng    = seededRng(seed);
  const idle   = Math.min(peak - 2, 34 + rng() * 6);   // 34–40 °C at idle
  const tau    = 2.5 + rng() * 2;                      // warm-up speed
  const peakAt = Math.round(minutes * (0.55 + rng() * 0.35));
  const temps  = [];
  for (let m = 0; m <= minutes; m++) {
    const base   = idle + (peak - idle) * (1 - Math.exp(-m / tau));
    const jitter = m === 0 ? 0 : rng() * 1.1;          // only downward: never exceeds the logged peak
    temps.push(Math.min(peak, base - jitter));
  }
  temps[peakAt] = peak;
  return temps.map(v => Math.round(v * 10) / 10);
}

const route  = useRoute();
const router = useRouter();

const hash   = ref('');
const state  = ref('choose');   // choose | loading | error | ready
const error  = ref(null);
const unit   = ref(null);
const diag   = ref(null);
const warranty = ref(null);
const picks  = ref([]);         // listed units (chooser, search, compare)
const chartCanvas = ref(null);
let chart = null;               // Chart.js instance (kept out of Vue reactivity)

const isDemo = computed(() => hash.value === DEMO_HASH);

// ---------------- Derived state ----------------
const passed  = computed(() => diag.value?.stress_status === 'PASSED');
const failed  = computed(() => diag.value?.stress_status === 'FAILED' || unit.value?.status === 'Rejected');
const ceiling = computed(() => diag.value?.temp_ceiling_celsius ?? session.rules.temp_ceiling_celsius);

const status = computed(() => {
  const s = unit.value?.status || 'Intake';
  return { tone: statusTone(s), ...(STATUS_META[s] || STATUS_META.Intake) };
});
const listedPrice = computed(() =>
  unit.value?.status === 'Certified' && unit.value.listing_price > 0 ? peso(unit.value.listing_price) : null);

const photoBroken = ref(false);
const photo = computed(() => (unit.value && !photoBroken.value ? productPhoto(unit.value.model_name) : null));

// ---------------- Cart: shoppers buy, visitors are asked to sign in, staff see neither ----------------
const canShop  = computed(() => session.canShop);
const isGuest  = computed(() => !session.user && !session.customer);
const inCart   = computed(() => cart.has(hash.value));
const cartFull = ref(false);
const addToCart = () => { cartFull.value = !cart.add(unit.value); };

const metrics = computed(() => {
  const d = diag.value, w = warranty.value, c = ceiling.value;
  const blank = (icon, label) => ({ icon, label, value: '—', unit: '', tone: '', sub: 'Not yet tested' });

  const temp = !d ? blank('fa-temperature-half', 'Peak Core Temp') : {
    icon: 'fa-temperature-half', label: 'Peak Core Temp',
    value: d.peak_temp_celsius.toFixed(1), unit: '°C',
    tone: d.peak_temp_celsius <= c ? 'pass' : 'fail',
    sub: d.peak_temp_celsius <= c
      ? `${(c - d.peak_temp_celsius).toFixed(1)} °C under the ${c.toFixed(1)} °C ceiling`
      : `${(d.peak_temp_celsius - c).toFixed(1)} °C over the ${c.toFixed(1)} °C ceiling`,
  };
  const fan = !d ? blank('fa-fan', 'Fan Profile') : {
    icon: 'fa-fan', label: 'Fan Profile', value: d.fan_speed_pct, unit: '%', tone: '',
    sub: d.fan_speed_pct >= 95 ? 'Maxed out at peak load' : 'Duty cycle at peak load',
  };
  const mem = !d ? blank('fa-memory', 'Memory Errors') : {
    icon: 'fa-memory', label: 'Memory Errors', value: d.memory_error_count, unit: '',
    tone: d.memory_error_count === 0 ? 'pass' : 'fail',
    sub: d.memory_error_count === 0 ? 'Clean memory scan' : 'Errors detected under load',
  };

  let warr;
  if (w.active) {
    warr = { value: 'Active', tone: 'pass', sub: `Covered until ${fmtDate(w.expires_on)}${w.from_sale ? ' (from purchase)' : ''}` };
  } else if (w.expires_on) {
    warr = { value: 'Expired', tone: '', sub: `Ended ${fmtDate(w.expires_on)}` };
  } else if (failed.value) {
    warr = { value: 'Void', tone: 'fail', sub: 'Not eligible — unit rejected' };
  } else {
    warr = { value: 'Pending', tone: '', sub: 'Issued on certification' };
  }
  return [temp, fan, mem, { icon: 'fa-shield-halved', label: 'Warranty Status', unit: '', ...warr }];
});

const checklist = computed(() => {
  const d = diag.value;
  const ICONS = { ok: 'fa-check', bad: 'fa-xmark', skip: 'fa-minus' };
  return [
    { state: d?.ultrasonic_cleaned ? 'ok' : 'skip', title: 'Ultrasonic PCB cleaning',
      detail: d?.ultrasonic_cleaned ? 'Board bathed and dried to remove dust & flux residue' : 'Not required for this unit' },
    { state: d?.thermal_paste_replaced ? 'ok' : 'skip', title: 'Fresh thermal paste',
      detail: d?.thermal_paste_replaced ? (d.paste_brand || 'Replaced') : 'Original application retained' },
    { state: !d ? 'skip' : passed.value ? 'ok' : 'bad', title: `${d?.test_duration_mins ?? 30}-minute stress test`,
      detail: d ? `${d.benchmark_tool} · ${d.stress_status}` : 'Not yet run' },
    { state: !d ? 'skip' : d.memory_error_count === 0 ? 'ok' : 'bad', title: 'Memory integrity scan',
      detail: d ? `${d.memory_error_count} error${d.memory_error_count === 1 ? '' : 's'} found` : 'Not yet run' },
  ].map(item => ({ ...item, icon: ICONS[item.state] }));
});

const signoff = computed(() => {
  const d = diag.value;
  const name = d?.tested_by || 'Pending';
  return {
    name,
    initials: name.split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase() || '—',
    date: d ? fmtDate(d.test_date) : '',
    role: passed.value ? 'Certified by' : d ? 'Inspected by' : 'Awaiting technician',
    stamp: passed.value ? 'Signed off' : d ? 'Rejected' : 'Pending',
    fail: !!d && !passed.value,
  };
});

// ---------------- Compare (same category only) ----------------
const compareHash  = ref('');
const compare      = ref(null);    // { component, diagnostic, warranty } of the other unit
const compareState = ref('idle');  // idle | loading | ready | error
const compareError = ref('');

const compareCandidates = computed(() =>
  unit.value ? picks.value.filter(p => p.category === unit.value.category && p.serial_hash !== hash.value) : []);

function setCompareUrl(h) {
  const query = { ...route.query };
  if (h) query.compare = h; else delete query.compare;
  router.replace({ query });
}

async function setCompare(h) {
  h = (h || '').toUpperCase();
  if (!h || (h === compareHash.value && compareState.value === 'ready')) h = '';   // clicking the selected one clears it
  compareHash.value = h;
  compare.value = null;
  compareError.value = '';
  setCompareUrl(h);
  if (!h) { compareState.value = 'idle'; drawChart(); return; }

  compareState.value = 'loading';
  try {
    const data = await api.get(`verify/${encodeURIComponent(h)}`);
    if (compareHash.value !== h) return;                // picked another one meanwhile
    if (data.component.category !== unit.value.category) {
      throw new Error(`You can only compare this ${unit.value.category} with another ${unit.value.category}.`);
    }
    if (data.component.serial_hash === hash.value) throw new Error('Pick a different unit to compare with.');
    compare.value = data;
    compareState.value = 'ready';
  } catch (e) {
    if (compareHash.value !== h) return;
    compareError.value = e.status === 404 ? 'That unit is not in the RigCraft registry.' : e.message;
    compareState.value = 'error';
    compareHash.value = '';
    setCompareUrl('');
  }
  drawChart();
}

/** Side-by-side rows: { label, a, b, better: 'a' | 'b' | null }. */
const compareRows = computed(() => {
  if (!compare.value || !unit.value) return [];
  const A = { c: unit.value, d: diag.value }, B = { c: compare.value.component, d: compare.value.diagnostic };
  const shop = (c) => picks.value.find(p => p.serial_hash === c.serial_hash);
  const forSale = (c) => c.status === 'Certified' && c.listing_price > 0;

  // prefer: which direction wins ('lower' | 'higher'); null = informational only
  const row = (label, get, show, prefer = null) => {
    const va = get(A), vb = get(B);
    let better = null;
    if (prefer && va != null && vb != null && va !== vb) better = (prefer === 'lower' ? va < vb : va > vb) ? 'a' : 'b';
    return { label, a: va == null ? '—' : show(va, A), b: vb == null ? '—' : show(vb, B), better };
  };
  const yesNo = (v) => (v ? 'Yes' : 'No');

  return [
    row('Price', x => (forSale(x.c) ? x.c.listing_price : null), v => peso(v), 'lower'),
    row('Brand-new price', x => shop(x.c)?.retail_price ?? null, v => pesoWhole(v)),
    row('You save', x => shop(x.c)?.savings ?? null, (v, x) => `${pesoWhole(v)} (${shop(x.c).savings_pct}%)`, 'higher'),
    row('Peak core temp', x => x.d?.peak_temp_celsius ?? null, v => `${v.toFixed(1)} °C`, 'lower'),
    row('Fan at peak load', x => x.d?.fan_speed_pct ?? null, v => `${v}%`, 'lower'),
    row('Memory errors', x => x.d?.memory_error_count ?? null, v => String(v), 'lower'),
    row('Stress test', x => (x.d ? `${x.d.benchmark_tool} · ${x.d.test_duration_mins} min · ${x.d.stress_status}` : null), v => v),
    row('Thermal paste', x => (x.d ? (x.d.thermal_paste_replaced ? (x.d.paste_brand || 'Replaced') : 'Original') : null), v => v),
    row('Ultrasonic clean', x => (x.d ? x.d.ultrasonic_cleaned : null), yesNo),
    row('Warranty', x => x.c.warranty_months, v => (v > 0 ? `${v} months` : 'None'), 'higher'),
    row('Tested on', x => x.d?.test_date ?? null, v => fmtDate(v)),
    row('Technician', x => x.d?.tested_by ?? null, v => v),
  ];
});
const compareWins = computed(() => ({
  a: compareRows.value.filter(r => r.better === 'a').length,
  b: compareRows.value.filter(r => r.better === 'b').length,
}));

const chartCaption = computed(() => {
  const d = diag.value;
  if (!d) return 'No stress run has been logged for this unit yet.';
  const od = compare.value?.diagnostic;
  return `Curve reconstructed from the logged peak of ${d.peak_temp_celsius.toFixed(1)} °C. ` +
         `Red dashed line marks RigCraft's ${ceiling.value.toFixed(1)} °C pass ceiling.` +
         (od ? ` Amber line: ${compare.value.component.model_name} (peak ${od.peak_temp_celsius.toFixed(1)} °C).` : '');
});

// ---------------- Chart ----------------
function drawChart() {
  const d = diag.value;
  if (!d || !chartCanvas.value) return;

  const minutes   = d.test_duration_mins || 30;
  const temps     = simulateRun(d.peak_temp_celsius, minutes, hash.value);
  const labels    = temps.map((_, m) => `${m}m`);
  const od        = compare.value?.diagnostic;
  // Other unit's curve, cut to this run's length so the minutes line up.
  const otherTemps = od ? simulateRun(od.peak_temp_celsius, od.test_duration_mins || 30, compare.value.component.serial_hash)
    .slice(0, temps.length) : null;
  const lineColor = passed.value ? '#06B6D4' : '#EF4444';
  const c         = ceiling.value;
  const tickEvery = minutes <= 30 ? 5 : Math.ceil(minutes / 6);

  Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
  Chart.defaults.color = '#94A3B8';

  const ctx  = chartCanvas.value.getContext('2d');
  const fill = ctx.createLinearGradient(0, 0, 0, 290);
  fill.addColorStop(0, passed.value ? 'rgba(6,182,212,.28)' : 'rgba(239,68,68,.25)');
  fill.addColorStop(1, 'rgba(15,23,42,0)');

  chart?.destroy();
  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: otherTemps ? 'This unit' : 'Core temp', short: 'This unit', data: temps, borderColor: lineColor, backgroundColor: fill, fill: true,
          borderWidth: 2.5, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, pointHoverBackgroundColor: lineColor },
        { label: `Threshold ${c.toFixed(1)} °C`, short: 'Ceiling', data: labels.map(() => c), borderColor: '#EF4444',
          borderDash: [6, 6], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 0, fill: false },
        ...(otherTemps ? [{
          label: compare.value.component.model_name, short: compare.value.component.model_name, data: otherTemps,
          borderColor: COMPARE_COLOR, borderWidth: 2, tension: 0.35, pointRadius: 0, pointHoverRadius: 4,
          pointHoverBackgroundColor: COMPARE_COLOR, fill: false,
        }] : []),
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { boxWidth: 14, boxHeight: 2 } },
        tooltip: {
          backgroundColor: '#0F172A', borderColor: '#334155', borderWidth: 1,
          titleColor: '#E2E8F0', bodyColor: '#E2E8F0', padding: 10,
          callbacks: { label: (it) => ` ${it.dataset.short}: ${it.parsed.y.toFixed(1)} °C` },
        },
      },
      scales: {
        x: { grid: { display: false },
             ticks: { autoSkip: false, maxRotation: 0, callback: (_, i) => (i % tickEvery === 0 ? labels[i] : '') } },
        y: { suggestedMin: 30, suggestedMax: Math.max(80, Math.ceil(Math.max(d.peak_temp_celsius, od?.peak_temp_celsius ?? 0) + 4)),
             grid: { color: 'rgba(148,163,184,.12)' }, ticks: { callback: (v) => `${v}°` } },
      },
    },
  });
}

// ---------------- Load ----------------
function describeError(e) {
  if (e.status === 404) return {
    icon: 'fa-triangle-exclamation', color: 'var(--rc-red)', title: 'Unregistered unit',
    message: 'This hash is not in the RigCraft registry. If a seller showed you this label, the component may not be a genuine RigCraft-certified unit.',
  };
  if (e.status === 400) return {
    icon: 'fa-triangle-exclamation', color: 'var(--rc-red)', title: 'Invalid label code',
    message: e.message || 'The code in this link is not a valid RigCraft hash.',
  };
  return {
    icon: 'fa-plug-circle-xmark', color: 'var(--rc-amber)', title: 'Verification service unavailable',
    message: e.message || 'Please try again in a moment.',
  };
}

async function load() {
  chart?.destroy(); chart = null;
  compare.value = null;
  compareState.value = 'idle'; compareError.value = ''; compareHash.value = '';
  photoBroken.value = false; cartFull.value = false;

  if (!hash.value) { state.value = 'choose'; document.title = 'Verify a Unit · RigCraft'; return; }

  state.value = 'loading';
  try {
    const data = await api.get(`verify/${encodeURIComponent(hash.value)}`);
    unit.value     = data.component;
    diag.value     = data.diagnostic;
    warranty.value = data.warranty;
    document.title = `${data.component.model_name} · RigCraft Verify`;
    state.value    = 'ready';
    await nextTick();              // wait for the <canvas> to exist
    drawChart();
    const want = String(route.query.compare || '').toUpperCase();   // ?compare= in a shared link
    if (want) setCompare(want);
  } catch (e) {
    error.value = describeError(e);
    state.value = 'error';
  }
}

/** Certified, for-sale units: chooser list, search suggestions, compare picks. Optional. */
async function loadPicks() {
  try {
    const data = await api.get('shop');
    picks.value = data.items.map(p => ({
      ...p, photo: productPhoto(p.model_name), icon: categoryIcon(p.category), price: pesoWhole(p.listing_price),
    }));
  } catch { /* the search box still accepts a typed hash */ }
}

// The same page handles /verify and /verify?hash=…; reload when the hash changes.
watch(() => String(route.query.hash || '').trim().toUpperCase(), (h) => {
  hash.value = h;
  load();
  window.scrollTo({ top: 0 });
}, { immediate: true });

onMounted(loadPicks);
onBeforeUnmount(() => chart?.destroy());

const signInToBuy = computed(() => ({ name: 'signin', query: { next: route.fullPath } }));
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-wide py-4">

      <!-- Choose a unit (no ?hash=) -->
      <template v-if="state === 'choose'">
        <section class="verify-pick text-center mx-auto">
          <div class="state-icon text-cyan mb-2"><i class="fa-solid fa-shield-halved"></i></div>
          <h1 class="h3 fw-bold mb-2">Verify a unit</h1>
          <p class="text-muted-rc mb-4">Every RigCraft component carries a QR label with its own hash. Scan it with your phone,
            or type the hash here to see that exact unit's stress-test report.</p>
          <div class="rc-card p-3 text-start">
            <label for="pick-hash" class="form-label">Product name or RigCraft hash</label>
            <UnitSearch input-id="pick-hash" :units="picks" autofocus primary />
          </div>
          <p class="small text-muted-rc mt-3 mb-0">
            No label handy? <RouterLink :to="{ name: 'verify', query: { hash: DEMO_HASH } }">View the demo unit</RouterLink>
          </p>
        </section>

        <section v-if="picks.length" class="mt-5">
          <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
            <div class="rc-card-title">Or pick a listed unit</div>
            <span class="small text-muted-rc">{{ picks.length }} certified unit{{ picks.length === 1 ? '' : 's' }} for sale</span>
          </div>
          <div class="row g-3">
            <div class="col-sm-6 col-lg-4" v-for="p in picks" :key="p.serial_hash">
              <RouterLink class="pick-card" :to="{ name: 'verify', query: { hash: p.serial_hash } }">
                <span class="cart-thumb" :class="{ 'has-photo': p.photo }">
                  <img v-if="p.photo" :src="p.photo" :alt="p.model_name" loading="lazy">
                  <i v-else class="fa-solid" :class="p.icon"></i>
                </span>
                <span class="min-w-0">
                  <span class="small text-muted-rc d-block">{{ p.brand }} · {{ p.category }}</span>
                  <span class="pick-name">{{ p.model_name }}</span>
                  <span class="mono small text-cyan d-block">{{ p.serial_hash }}</span>
                </span>
                <i class="fa-solid fa-chevron-right ms-auto text-muted-rc"></i>
              </RouterLink>
            </div>
          </div>
        </section>
      </template>

      <!-- Loading -->
      <div v-else-if="state === 'loading'" class="state-box">
        <div class="spinner-border text-info" role="status"></div>
        <p class="mt-3 text-muted-rc">Looking up this unit in the RigCraft registry…</p>
      </div>

      <!-- Not found / error -->
      <div v-else-if="state === 'error'" class="state-box">
        <div class="state-icon mb-3" :style="{ color: error.color }"><i class="fa-solid" :class="error.icon"></i></div>
        <h1 class="h4 fw-bold">{{ error.title }}</h1>
        <p class="text-muted-rc">{{ error.message }}</p>
        <p class="mono small text-muted-rc">{{ hash }}</p>
        <RouterLink :to="{ name: 'verify' }" class="btn btn-outline-info btn-sm mt-2"><i class="fa-solid fa-magnifying-glass me-1"></i>Choose another unit</RouterLink>
      </div>

      <!-- Loaded -->
      <template v-else>
        <div v-if="isDemo" class="demo-banner px-3 py-2 mb-3">
          <i class="fa-solid fa-circle-info me-1 text-cyan"></i>
          You're viewing the <strong>demo unit</strong>. Scan the QR label on a RigCraft component, or enter its hash below, to verify that exact unit.
          <RouterLink :to="{ name: 'verify' }" class="ms-1">Choose a unit</RouterLink>
        </div>

        <!-- Identity + status -->
        <section class="rc-card hero-unit mb-3" :class="'is-' + status.tone">
          <div class="unit-top" :class="{ 'has-photo': photo }">
            <div class="unit-top-text">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="status-pill" :class="status.tone"><i class="fa-solid" :class="status.icon"></i>{{ status.label }}</span>
                <span class="small text-muted-rc">{{ unit.brand }} · {{ unit.category }}</span>
              </div>
              <h1 class="unit-name" :class="listedPrice ? 'mb-1' : 'mb-0'">{{ unit.model_name }}</h1>
              <div v-if="listedPrice" class="d-flex flex-wrap align-items-center gap-3">
                <span class="text-muted-rc">Listed at <strong class="text-white">{{ listedPrice }}</strong></span>
                <template v-if="canShop">
                  <RouterLink v-if="inCart" :to="{ name: 'cart' }" class="btn btn-sm btn-in-cart"><i class="fa-solid fa-check me-1"></i>In your cart · View cart</RouterLink>
                  <button v-else type="button" class="btn btn-sm btn-rc" @click="addToCart"><i class="fa-solid fa-cart-plus me-1"></i>Add to cart</button>
                  <span v-if="cartFull" class="small text-warning">Your cart is full. Check out first, then keep shopping.</span>
                </template>
                <RouterLink v-else-if="isGuest" :to="signInToBuy" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-right-to-bracket me-1"></i>Sign in to buy</RouterLink>
              </div>
            </div>
            <ProductZoom v-if="photo" :src="photo" :alt="unit.brand + ' ' + unit.model_name" @error="photoBroken = true" />
          </div>

          <dl class="spec-grid cols-5">
            <div><dt>Physical S/N</dt><dd class="mono">{{ unit.serial_number }}</dd></div>
            <div><dt>RigCraft Hash</dt><dd class="mono text-cyan">{{ unit.serial_hash }}</dd></div>
            <div><dt>Technician</dt><dd>{{ diag ? diag.tested_by : '—' }}</dd></div>
            <div><dt>Tested On</dt><dd>{{ diag ? fmtDate(diag.test_date) : '—' }}</dd></div>
            <div><dt>Warranty</dt><dd>{{ warranty.months > 0 ? warranty.months + ' months' : 'None' }}</dd></div>
          </dl>
        </section>

        <!-- Metric cards -->
        <section class="row g-3 mb-3">
          <div class="col-6 col-lg-3" v-for="m in metrics" :key="m.label">
            <div class="rc-card fill metric" :class="m.tone ? 'is-' + m.tone : ''">
              <div class="metric-label"><i class="fa-solid" :class="m.icon"></i>{{ m.label }}</div>
              <div class="metric-value">{{ m.value }}<small v-if="m.unit">{{ m.unit }}</small></div>
              <div class="metric-sub">{{ m.sub }}</div>
            </div>
          </div>
        </section>

        <!-- Stress chart + checklist -->
        <section class="row g-3">
          <div class="col-lg-8">
            <div class="rc-card fill">
              <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                <div class="rc-card-title">Thermal Stress Run</div>
                <span v-if="diag" class="small text-muted-rc">{{ diag.benchmark_tool }} · {{ diag.test_duration_mins }} min</span>
              </div>
              <div v-if="diag" class="chart-wrap mt-3">
                <canvas ref="chartCanvas" role="img" aria-label="Core temperature over the stress test"></canvas>
              </div>
              <p class="small text-muted-rc mt-2 mb-0">{{ chartCaption }}</p>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="rc-card fill">
              <div class="rc-card-title mb-3">Refurbishment Checklist</div>
              <ul class="check-list">
                <li v-for="item in checklist" :key="item.title">
                  <span class="check-icon" :class="item.state"><i class="fa-solid" :class="item.icon"></i></span>
                  <div>
                    <div class="check-title">{{ item.title }}</div>
                    <div class="check-detail">{{ item.detail }}</div>
                  </div>
                </li>
              </ul>
              <div class="signoff" :class="{ 'is-fail': signoff.fail }">
                <div class="signoff-avatar">{{ signoff.initials }}</div>
                <div class="min-w-0">
                  <div class="small text-muted-rc">{{ signoff.role }}</div>
                  <div class="fw-semibold">{{ signoff.name }}</div>
                  <div class="small text-muted-rc">{{ signoff.date }}</div>
                </div>
                <span class="signoff-stamp">{{ signoff.stamp }}</span>
              </div>
            </div>
          </div>
        </section>

        <!-- Compare with another unit of the same category -->
        <section class="rc-card mt-3 compare-card" aria-labelledby="compare-title">
          <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
            <div>
              <h2 class="rc-card-title mb-1" id="compare-title"><i class="fa-solid fa-code-compare me-1"></i>Compare with another {{ unit.category }}</h2>
              <p class="small text-muted-rc mb-0">Side by side with another certified {{ unit.category }} that's for sale. Only the same type of part can be compared.</p>
            </div>
            <button v-if="compareState === 'ready'" type="button" class="btn btn-sm btn-link text-decoration-none px-0" @click="setCompare('')">
              <i class="fa-solid fa-xmark me-1"></i>Clear comparison
            </button>
          </div>

          <div v-if="compareCandidates.length" class="compare-picks" role="group" :aria-label="'Pick a ' + unit.category + ' to compare'">
            <button v-for="p in compareCandidates" :key="p.serial_hash" type="button" class="compare-pick"
                    :class="{ active: compareHash === p.serial_hash }" :aria-pressed="compareHash === p.serial_hash" @click="setCompare(p.serial_hash)">
              <span class="cart-thumb" :class="{ 'has-photo': p.photo }">
                <img v-if="p.photo" :src="p.photo" alt="" loading="lazy">
                <i v-else class="fa-solid" :class="p.icon"></i>
              </span>
              <span class="min-w-0 text-start">
                <span class="pick-name">{{ p.model_name }}</span>
                <span class="small text-muted-rc d-block">{{ p.price }} · {{ p.peak_temp_celsius.toFixed(1) }} °C peak</span>
              </span>
              <i class="fa-solid ms-auto" :class="compareHash === p.serial_hash ? 'fa-circle-check text-amber' : 'fa-plus text-muted-rc'"></i>
            </button>
          </div>
          <p v-else class="small text-muted-rc mb-0"><i class="fa-solid fa-circle-info me-1"></i>No other {{ unit.category }} is for sale right now, so there's nothing to compare with yet.</p>

          <div v-if="compareState === 'loading'" class="text-center py-4"><div class="spinner-border spinner-border-sm text-info" role="status"></div></div>
          <div v-else-if="compareState === 'error'" class="alert alert-warning py-2 small mt-3 mb-0">{{ compareError }}</div>

          <div v-else-if="compareState === 'ready'" class="table-responsive mt-3">
            <table class="table table-rc compare-table mb-0">
              <thead>
                <tr>
                  <th scope="col"><span class="visually-hidden">Spec</span></th>
                  <th scope="col">
                    <span class="compare-key" :style="{ background: status.tone === 'fail' ? 'var(--rc-red)' : 'var(--rc-cyan)' }"></span>This unit
                    <div class="compare-model">{{ unit.model_name }}</div>
                    <span class="mono small text-cyan">{{ unit.serial_hash }}</span>
                  </th>
                  <th scope="col">
                    <span class="compare-key" :style="{ background: COMPARE_COLOR }"></span>Compared
                    <div class="compare-model">{{ compare.component.model_name }}</div>
                    <RouterLink class="mono small" :to="{ name: 'verify', query: { hash: compare.component.serial_hash } }">{{ compare.component.serial_hash }} <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i></RouterLink>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in compareRows" :key="r.label">
                  <th scope="row">{{ r.label }}</th>
                  <td :class="{ 'is-better': r.better === 'a' }">{{ r.a }}<i v-if="r.better === 'a'" class="fa-solid fa-circle-check ms-2" aria-label="better"></i></td>
                  <td :class="{ 'is-better': r.better === 'b' }">{{ r.b }}<i v-if="r.better === 'b'" class="fa-solid fa-circle-check ms-2" aria-label="better"></i></td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <th scope="row">Wins</th>
                  <td class="fw-bold">{{ compareWins.a }}</td>
                  <td class="fw-bold">{{ compareWins.b }}</td>
                </tr>
              </tfoot>
            </table>
            <p class="small text-muted-rc mt-2 mb-0">Green marks the better value: lower price, temperature, fan speed and errors; bigger savings and longer warranty.
              The amber line on the stress chart above is the compared unit.</p>
          </div>
        </section>
      </template>

      <!-- Look up another unit -->
      <div v-if="state === 'ready' || state === 'error'" class="rc-card mt-4 d-sm-flex align-items-center gap-3">
        <label for="lookup" class="fw-semibold mb-2 mb-sm-0 flex-shrink-0"><i class="fa-solid fa-magnifying-glass me-1 text-cyan"></i>Verify another unit</label>
        <UnitSearch input-id="lookup" :units="picks" :exclude="hash" />
      </div>
    </div>
  </main>
</template>
