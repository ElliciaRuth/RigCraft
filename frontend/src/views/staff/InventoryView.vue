<script setup>
/** Inventory: search, filter, sort and page through every unit. Filters live in the URL so links and Back work. */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/lib/api.js';
import { peso, fmtDate, statusTone, debounce, CATEGORIES, STATUSES, STATUS_ICONS, CATEGORY_ICONS } from '@/lib/format.js';

const PER_PAGE = 20;
const route  = useRoute();
const router = useRouter();

const q0 = route.query;
const f = reactive({
  q:        String(q0.q || ''),
  status:   STATUSES.includes(q0.status) ? q0.status : '',
  category: CATEGORIES.includes(q0.category) ? q0.category : '',
  sort:     String(q0.sort || 'newest'),
  page:     Math.max(1, parseInt(q0.page || '1', 10) || 1),
});

const items   = ref([]);
const total   = ref(0);
const pages   = ref(1);
const counts  = ref({});
const loading = ref(false);
const error   = ref('');
let requestId = 0;

const allCount      = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));
const filtersActive = computed(() => !!(f.q || f.status || f.category));
const rangeStart    = computed(() => (total.value ? (f.page - 1) * PER_PAGE + 1 : 0));
const rangeEnd      = computed(() => Math.min(total.value, f.page * PER_PAGE));
const pageList      = computed(() => {
  const out = [], start = Math.max(1, f.page - 2), end = Math.min(pages.value, start + 4);
  for (let p = Math.max(1, end - 4); p <= end; p++) out.push(p);
  return out;
});

function syncUrl() {
  const query = {};
  if (f.q) query.q = f.q;
  if (f.status) query.status = f.status;
  if (f.category) query.category = f.category;
  if (f.sort !== 'newest') query.sort = f.sort;
  if (f.page > 1) query.page = String(f.page);
  router.replace({ query });
}

async function load() {
  const id = ++requestId;          // ignore out-of-order responses while typing
  loading.value = true;
  error.value = '';
  syncUrl();
  try {
    const data = await api.get('units', { ...f, per_page: PER_PAGE });
    if (id !== requestId) return;
    items.value  = data.items;
    total.value  = data.total;
    pages.value  = data.pages;
    counts.value = data.counts;
    if (f.page > data.pages) f.page = data.pages;
  } catch (e) {
    if (id === requestId) error.value = e.message;
  } finally {
    if (id === requestId) loading.value = false;
  }
}
const loadSoon = debounce(load, 250);

// Any filter change resets to page 1; typing is debounced.
watch(() => f.q, () => { f.page = 1; loadSoon(); });
watch(() => [f.status, f.category, f.sort], () => { f.page = 1; load(); });
watch(() => f.page, load);

const go = (p) => { if (p >= 1 && p <= pages.value && p !== f.page) { f.page = p; window.scrollTo({ top: 0, behavior: 'smooth' }); } };
const clearFilters = () => { Object.assign(f, { q: '', status: '', category: '' }); };
const open = (hash) => router.push({ name: 'unit', params: { hash } });

// Price column: what it sold for, or the listing price.
const priceOf  = (it) => (it.status === 'Sold' ? peso(it.sold_price) : it.listing_price > 0 ? peso(it.listing_price) : '—');
const marginOf = (it) => {
  if (it.status === 'Sold') return it.sold_price - it.buyout_cost;
  if (it.status === 'Rejected' || !(it.listing_price > 0)) return null;
  return it.listing_price - it.buyout_cost;
};

onMounted(load);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
          <h1 class="page-title">Inventory</h1>
          <p class="page-sub mb-0">{{ total }} unit{{ total === 1 ? '' : 's' }}{{ filtersActive ? ' match your filters' : ' on record' }}</p>
        </div>
        <RouterLink :to="{ name: 'intake' }" class="btn btn-rc"><i class="fa-solid fa-plus me-1"></i>New intake</RouterLink>
      </div>

      <!-- Status chips -->
      <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filter by status">
        <button type="button" class="chip" :class="{ active: f.status === '' }" @click="f.status = ''">All <span class="count">{{ allCount }}</span></button>
        <button v-for="st in STATUSES" :key="st" type="button" class="chip" :class="{ active: f.status === st }" @click="f.status = st">
          <i class="fa-solid" :class="STATUS_ICONS[st]"></i>{{ st }} <span class="count">{{ counts[st] || 0 }}</span>
        </button>
      </div>

      <!-- Toolbar -->
      <div class="rc-card mb-3">
        <div class="row g-2 align-items-center">
          <div class="col-md-6">
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input class="form-control" type="search" v-model="f.q" placeholder="Search hash, model, brand or serial number" aria-label="Search inventory">
            </div>
          </div>
          <div class="col-6 col-md-3">
            <select class="form-select" v-model="f.category" aria-label="Category">
              <option value="">All categories</option>
              <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <select class="form-select" v-model="f.sort" aria-label="Sort">
              <option value="newest">Newest intake</option>
              <option value="oldest">Oldest intake</option>
              <option value="price_desc">Price: high to low</option>
              <option value="price_asc">Price: low to high</option>
              <option value="temp_desc">Hottest peak temp</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Table -->
      <section class="rc-card p-0">
        <div v-if="error" class="alert alert-danger m-3">{{ error }}</div>
        <div class="table-responsive">
          <table class="table table-rc table-hover align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">Unit</th><th>Category</th><th class="text-end">Peak</th><th>Status</th>
                <th class="text-end">Cost</th><th class="text-end">Price</th><th class="text-end">Margin</th><th>Intake</th><th class="pe-3"></th>
              </tr>
            </thead>
            <tbody :style="{ opacity: loading ? .5 : 1 }">
              <tr v-if="!items.length && !loading"><td colspan="9" class="empty-state">
                <i class="fa-solid fa-box-open"></i><p class="mt-2 mb-2">No units match.</p>
                <button v-if="filtersActive" class="btn btn-sm btn-outline-info" @click="clearFilters">Clear filters</button>
              </td></tr>
              <tr v-for="it in items" :key="it.serial_hash" class="clickable" tabindex="0"
                  @click="open(it.serial_hash)" @keydown.enter="open(it.serial_hash)">
                <td class="ps-3">
                  <div class="fw-semibold">{{ it.model_name }}</div>
                  <div class="small mono text-cyan">{{ it.serial_hash }} <span class="text-muted-rc">· S/N {{ it.serial_number }}</span></div>
                </td>
                <td><span class="text-muted-rc"><i class="fa-solid me-1" :class="CATEGORY_ICONS[it.category]"></i>{{ it.category }}</span></td>
                <td class="text-end tabular" :class="it.stress_status === 'FAILED' ? 'text-red' : ''">{{ it.peak_temp_celsius == null ? '—' : it.peak_temp_celsius.toFixed(1) + ' °C' }}</td>
                <td><span class="tag" :class="statusTone(it.status)">{{ it.status }}</span></td>
                <td class="text-end tabular text-muted-rc">{{ peso(it.buyout_cost) }}</td>
                <td class="text-end tabular">{{ priceOf(it) }}</td>
                <td class="text-end tabular" :class="marginOf(it) === null ? '' : marginOf(it) >= 0 ? 'text-emerald' : 'text-red'">{{ marginOf(it) === null ? '—' : peso(marginOf(it)) }}</td>
                <td class="text-nowrap text-muted-rc small">{{ fmtDate(it.intake_date) }}</td>
                <td class="pe-3 text-end"><i class="fa-solid fa-chevron-right text-muted-rc"></i></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="pages > 1" class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top" style="border-color: var(--rc-border) !important">
          <span class="small text-muted-rc">Showing {{ rangeStart }}–{{ rangeEnd }} of {{ total }}</span>
          <nav aria-label="Inventory pages">
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item" :class="{ disabled: f.page <= 1 }"><button class="page-link" @click="go(f.page - 1)">‹</button></li>
              <li v-for="p in pageList" :key="p" class="page-item" :class="{ active: p === f.page }"><button class="page-link" @click="go(p)">{{ p }}</button></li>
              <li class="page-item" :class="{ disabled: f.page >= pages }"><button class="page-link" @click="go(f.page + 1)">›</button></li>
            </ul>
          </nav>
        </div>
      </section>
    </div>
  </main>
</template>
