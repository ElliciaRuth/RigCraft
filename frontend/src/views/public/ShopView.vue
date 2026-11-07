<script setup>
/**
 * Shop: every certified unit for sale, with filters (search, category, brand,
 * price, peak temperature, discounted only) and sorting. Filters live in the
 * URL so a filtered view can be shared or refreshed.
 */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { pesoWhole, categoryIcon } from '@/lib/format.js';
import { useShopCart } from '@/lib/useShopCart.js';
import DealCard from '@/components/DealCard.vue';
import CartToast from '@/components/CartToast.vue';

const SORTS = {
  savings:    { label: 'Biggest savings', by: (a, b) => (b.savings_pct ?? -1) - (a.savings_pct ?? -1) },
  price_asc:  { label: 'Lowest price',    by: (a, b) => a.listing_price - b.listing_price },
  price_desc: { label: 'Highest price',   by: (a, b) => b.listing_price - a.listing_price },
  coolest:    { label: 'Coolest running', by: (a, b) => a.peak_temp_celsius - b.peak_temp_celsius },
  newest:     { label: 'Newest arrivals', by: () => 0 },   // API already returns newest first
};
const list = (s) => (s ? String(s).split(',').map(v => v.trim()).filter(Boolean) : []);
const num  = (s) => (s === null || s === undefined || s === '' || isNaN(Number(s)) ? null : Number(s));

const route  = useRoute();
const router = useRouter();
const guest  = computed(() => !session.user && !session.customer);

const state = ref('loading');
const error = ref('');
const items = ref([]);
const stats = ref(null);
const ceiling = computed(() => session.rules.temp_ceiling_celsius);

// ---------------- Filters (seeded from the URL) ----------------
const qp = route.query;
const f = reactive({
  q:          String(qp.q || ''),
  categories: list(qp.cat),
  brands:     list(qp.brand),
  min:        num(qp.min),
  max:        num(qp.max),
  temp:       num(qp.temp),     // max peak °C; null = any
  sale:       qp.sale === '1',
});
const sort = ref(SORTS[qp.sort] ? qp.sort : 'savings');

// One predicate per filter, so each facet's counts can ignore its own filter.
const tests = {
  q: (p) => {
    const tokens = f.q.toLowerCase().split(/\s+/).filter(Boolean);
    const hay = `${p.model_name} ${p.brand} ${p.category} ${p.serial_hash}`.toLowerCase();
    return tokens.every(t => hay.includes(t));
  },
  categories: (p) => !f.categories.length || f.categories.includes(p.category),
  brands:     (p) => !f.brands.length || f.brands.includes(p.brand),
  price:      (p) => (f.min === null || p.listing_price >= f.min) && (f.max === null || p.listing_price <= f.max),
  temp:       (p) => f.temp === null || p.peak_temp_celsius <= f.temp,
  sale:       (p) => !f.sale || !!p.savings,
};
const passes = (p, skip = '') => Object.entries(tests).every(([k, t]) => k === skip || t(p));

const visible = computed(() => items.value.filter(p => passes(p)).sort(SORTS[sort.value].by));

/** [{ value, n }] for a facet, counted against every other active filter. */
function facet(key, field) {
  const counts = {};
  items.value.forEach((p) => { counts[p[field]] ??= 0; });
  items.value.filter(p => passes(p, key)).forEach((p) => { counts[p[field]]++; });
  return Object.entries(counts).map(([value, n]) => ({ value, n }))
    .sort((a, b) => b.n - a.n || a.value.localeCompare(b.value));
}
const categoryFacet = computed(() => facet('categories', 'category'));
const brandFacet    = computed(() => facet('brands', 'brand'));

const priceBounds = computed(() => {
  const prices = items.value.map(p => p.listing_price);
  return prices.length ? { lo: Math.floor(Math.min(...prices)), hi: Math.ceil(Math.max(...prices)) } : { lo: 0, hi: 0 };
});
const tempBounds = computed(() => {
  const temps = items.value.map(p => p.peak_temp_celsius);
  return { lo: temps.length ? Math.floor(Math.min(...temps)) : 40, hi: Math.ceil(ceiling.value) };
});
const tempValue = computed({
  get: () => f.temp ?? tempBounds.value.hi,
  set: (v) => { f.temp = Number(v) >= tempBounds.value.hi ? null : Number(v); },
});
const saleCount = computed(() => items.value.filter(p => passes(p, 'sale') && p.savings).length);

/** Removable chips for the active filters. */
const activeFilters = computed(() => {
  const out = [];
  if (f.q.trim()) out.push({ key: 'q', label: `“${f.q.trim()}”`, clear: () => { f.q = ''; } });
  f.categories.forEach(c => out.push({ key: 'c-' + c, label: c, clear: () => { f.categories = f.categories.filter(x => x !== c); } }));
  f.brands.forEach(b => out.push({ key: 'b-' + b, label: b, clear: () => { f.brands = f.brands.filter(x => x !== b); } }));
  if (f.min !== null || f.max !== null) {
    const label = f.min !== null && f.max !== null ? `${pesoWhole(f.min)} – ${pesoWhole(f.max)}`
      : f.min !== null ? `From ${pesoWhole(f.min)}` : `Up to ${pesoWhole(f.max)}`;
    out.push({ key: 'price', label, clear: () => { f.min = null; f.max = null; } });
  }
  if (f.temp !== null) out.push({ key: 'temp', label: `≤ ${f.temp} °C peak`, clear: () => { f.temp = null; } });
  if (f.sale) out.push({ key: 'sale', label: 'Below new price', clear: () => { f.sale = false; } });
  return out;
});

function clearAll() {
  Object.assign(f, { q: '', categories: [], brands: [], min: null, max: null, temp: null, sale: false });
}
const setPrice = (key, v) => { f[key] = num(v); };   // number inputs give '' when emptied

// ---------------- URL sync ----------------
watch([f, sort], () => {
  const query = {};
  const set = (k, v) => { if (v !== null && v !== '' && v !== false) query[k] = String(v); };
  set('q', f.q.trim());
  set('cat', f.categories.join(','));
  set('brand', f.brands.join(','));
  set('min', f.min);
  set('max', f.max);
  set('temp', f.temp);
  set('sale', f.sale ? '1' : '');
  set('sort', sort.value === 'savings' ? '' : sort.value);
  router.replace({ query });
}, { deep: true });

// ---------------- Cards ----------------
const bestPct = computed(() => stats.value?.max_savings_pct || 0);
const { canShop, inCart, addToCart, cartNotice } = useShopCart();

onMounted(async () => {
  try {
    const data = await api.get('shop');
    items.value = data.items;
    stats.value = data.stats;
    state.value = 'ready';
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
});
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-wide py-4 py-lg-5">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
          <div class="eyebrow"><i class="fa-solid fa-store me-1"></i>Shop</div>
          <h1 class="page-title fs-2">All certified parts</h1>
          <p class="page-sub mb-0">Every unit is cleaned, re-pasted and stress-tested. Each one has its own test report.</p>
          <p v-if="guest" class="small mt-1 mb-0">
            <RouterLink :to="{ name: 'signin', query: { next: '/shop' } }" class="text-decoration-none"><i class="fa-solid fa-right-to-bracket me-1"></i>Sign in</RouterLink>
            or <RouterLink :to="{ name: 'register', query: { next: '/shop' } }" class="text-decoration-none">create an account</RouterLink> to add parts to your cart.
          </p>
        </div>
      </div>

      <div class="row g-4">
        <!-- ============ Results ============ -->
        <div class="col-lg-9">
          <div class="shop-toolbar mb-3">
            <span class="small text-muted-rc me-auto" role="status" aria-live="polite">
              <template v-if="state === 'ready'"><strong class="text-white">{{ visible.length }}</strong> of {{ items.length }} unit{{ items.length === 1 ? '' : 's' }}</template>
            </span>
            <button type="button" class="btn btn-sm btn-outline-info d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#shopFilters" aria-controls="shopFilters">
              <i class="fa-solid fa-sliders me-1"></i>Filters<span v-if="activeFilters.length" class="badge text-bg-info ms-1">{{ activeFilters.length }}</span>
            </button>
            <label class="visually-hidden" for="shop-sort">Sort by</label>
            <select id="shop-sort" class="form-select form-select-sm w-auto" v-model="sort">
              <option v-for="(s, key) in SORTS" :key="key" :value="key">{{ s.label }}</option>
            </select>
          </div>

          <div v-if="activeFilters.length" class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <button v-for="a in activeFilters" :key="a.key" type="button" class="chip active" @click="a.clear()" :aria-label="'Remove filter ' + a.label">
              {{ a.label }} <i class="fa-solid fa-xmark"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-decoration-none px-1" @click="clearAll">Clear all</button>
          </div>

          <div v-if="state === 'loading'" class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-4">
            <div class="col" v-for="n in 6" :key="n"><div class="skeleton"></div></div>
          </div>
          <div v-else-if="state === 'error'" class="alert alert-warning">{{ error }}</div>
          <div v-else-if="!items.length" class="rc-card empty-state">
            <i class="fa-solid fa-box-open"></i>
            <p class="mt-3 mb-0">Everything is sold out right now. New units are being tested — check back soon.</p>
          </div>
          <div v-else-if="!visible.length" class="rc-card empty-state">
            <i class="fa-solid fa-filter-circle-xmark"></i>
            <p class="mt-3 mb-3">No units match these filters.</p>
            <button type="button" class="btn btn-sm btn-outline-info" @click="clearAll">Clear all filters</button>
          </div>

          <TransitionGroup v-else name="deal" tag="div" class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-4 deals-grid">
            <div class="col" v-for="(p, i) in visible" :key="p.serial_hash" :style="{ '--i': Math.min(i, 8) }">
              <DealCard :item="p" :best="!!p.savings_pct && p.savings_pct === bestPct" :in-cart="inCart(p.serial_hash)"
                        :can-shop="canShop" @add="addToCart" />
            </div>
          </TransitionGroup>
        </div>

        <!-- ============ Filters (left column; slide-in panel on small screens) ============ -->
        <div class="col-lg-3 order-lg-first">
          <aside class="offcanvas-lg offcanvas-start shop-filters" tabindex="-1" id="shopFilters" aria-labelledby="shopFiltersTitle">
            <div class="offcanvas-header">
              <h2 class="h5 mb-0" id="shopFiltersTitle">Filters</h2>
              <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#shopFilters" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body">
              <div class="rc-card filter-card w-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <div class="rc-card-title"><i class="fa-solid fa-sliders me-1"></i>Filters</div>
                  <button v-if="activeFilters.length" type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="clearAll">Clear all</button>
                </div>

                <div class="filter-group">
                  <label class="filter-title" for="shop-q">Search</label>
                  <div class="position-relative">
                    <i class="fa-solid fa-magnifying-glass filter-search-icon"></i>
                    <input id="shop-q" v-model="f.q" type="search" class="form-control form-control-sm ps-4" placeholder="Model, brand or hash" autocomplete="off">
                  </div>
                </div>

                <fieldset class="filter-group">
                  <legend class="filter-title">Category</legend>
                  <div v-for="c in categoryFacet" :key="c.value" class="form-check">
                    <input class="form-check-input" type="checkbox" :id="'cat-' + c.value" :value="c.value" v-model="f.categories" :disabled="!c.n && !f.categories.includes(c.value)">
                    <label class="form-check-label filter-option" :for="'cat-' + c.value">
                      <span><i class="fa-solid fa-fw me-1 text-muted-rc" :class="categoryIcon(c.value)"></i>{{ c.value }}</span><span class="filter-count">{{ c.n }}</span>
                    </label>
                  </div>
                </fieldset>

                <fieldset class="filter-group">
                  <legend class="filter-title">Brand</legend>
                  <div v-for="b in brandFacet" :key="b.value" class="form-check">
                    <input class="form-check-input" type="checkbox" :id="'brand-' + b.value" :value="b.value" v-model="f.brands" :disabled="!b.n && !f.brands.includes(b.value)">
                    <label class="form-check-label filter-option" :for="'brand-' + b.value">
                      <span>{{ b.value }}</span><span class="filter-count">{{ b.n }}</span>
                    </label>
                  </div>
                </fieldset>

                <fieldset class="filter-group">
                  <legend class="filter-title">Price (₱)</legend>
                  <div class="d-flex align-items-center gap-2">
                    <input type="number" class="form-control form-control-sm" aria-label="Minimum price" min="0" step="500"
                           :placeholder="priceBounds.lo.toLocaleString('en-PH')" :value="f.min" @change="setPrice('min', $event.target.value)">
                    <span class="text-muted-rc">–</span>
                    <input type="number" class="form-control form-control-sm" aria-label="Maximum price" min="0" step="500"
                           :placeholder="priceBounds.hi.toLocaleString('en-PH')" :value="f.max" @change="setPrice('max', $event.target.value)">
                  </div>
                </fieldset>

                <fieldset class="filter-group">
                  <legend class="filter-title d-flex justify-content-between">
                    <span>Peak temperature</span>
                    <span class="text-cyan">{{ f.temp === null ? 'Any' : '≤ ' + f.temp + ' °C' }}</span>
                  </legend>
                  <input type="range" class="form-range" :min="tempBounds.lo" :max="tempBounds.hi" step="1" v-model="tempValue"
                         aria-label="Maximum peak temperature under load">
                  <div class="d-flex justify-content-between small text-muted-rc"><span>{{ tempBounds.lo }} °C</span><span>{{ tempBounds.hi }} °C ceiling</span></div>
                  <p class="small text-muted-rc mt-1 mb-0">Lower means the part ran cooler in its 30-minute stress test.</p>
                </fieldset>

                <div class="filter-group border-0 pb-0 mb-0">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="shop-sale" v-model="f.sale">
                    <label class="form-check-label filter-option" for="shop-sale">
                      <span>Below brand-new price</span><span class="filter-count">{{ saleCount }}</span>
                    </label>
                  </div>
                </div>

                <button type="button" class="btn btn-rc w-100 mt-3 d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#shopFilters">
                  Show {{ visible.length }} unit{{ visible.length === 1 ? '' : 's' }}
                </button>
              </div>
            </div>
          </aside>
        </div>
      </div>

      <CartToast :message="cartNotice" />
    </div>
  </main>
</template>
