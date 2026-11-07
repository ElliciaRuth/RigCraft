<script setup>
/**
 * Type-ahead for the verify page: type a product name, brand, category or
 * hash and pick from the listed units, or enter a full hash from a label.
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';

const props = defineProps({
  units:     { type: Array, default: () => [] },
  inputId:   { type: String, required: true },
  exclude:   { type: String, default: '' },     // the unit already on screen
  autofocus: { type: Boolean, default: false },
  primary:   { type: Boolean, default: false },
});

const HASH_RE = /^RC-[A-Z0-9]{1,10}-\d{4}$/;   // same rule as the backend
const router  = useRouter();
const goTo    = (h) => router.push({ name: 'verify', query: { hash: h } });

const q      = ref('');
const open   = ref(false);
const active = ref(-1);
const input  = ref(null);
const listId = props.inputId + '-list';

const query   = computed(() => q.value.trim());
const pool    = computed(() => props.units.filter(u => u.serial_hash !== props.exclude));
const matches = computed(() => {
  const tokens = query.value.toLowerCase().split(/\s+/).filter(Boolean);
  if (!tokens.length) return pool.value.slice(0, 6);
  return pool.value.filter((u) => {
    const hay = `${u.model_name} ${u.brand} ${u.category} ${u.serial_hash}`.toLowerCase();
    return tokens.every(t => hay.includes(t));
  }).slice(0, 8);
});
const looksLikeHash = computed(() => HASH_RE.test(query.value.toUpperCase()));
const note = computed(() => {
  if (!query.value || matches.value.length) return '';
  return looksLikeHash.value
    ? `Press Enter to verify ${query.value.toUpperCase()}.`
    : 'No listed unit matches. Enter the full hash from the label, e.g. RC-3070TI-8941.';
});
const showList = computed(() => open.value && (matches.value.length > 0 || !!note.value));

function submit() {
  if (open.value && active.value >= 0 && matches.value[active.value]) return pick(matches.value[active.value]);
  const Q = query.value.toUpperCase();
  if (!Q) return;
  if (HASH_RE.test(Q)) return done(Q);
  if (matches.value.length === 1) return pick(matches.value[0]);
  open.value = true;                       // several (or no) matches: let them choose
  active.value = matches.value.length ? 0 : -1;
}
function pick(u) { done(u.serial_hash); }
function done(h) { q.value = ''; open.value = false; active.value = -1; goTo(h); }

function onKey(e) {
  const n = matches.value.length;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    if (!open.value) { open.value = true; return; }
    if (!n) return;
    const step = e.key === 'ArrowDown' ? 1 : -1;
    active.value = active.value < 0 && step < 0 ? n - 1 : (active.value + step + n) % n;
  } else if (e.key === 'Escape') {
    open.value = false; active.value = -1;
  }
}
function onInput() { open.value = true; active.value = -1; }

onMounted(() => { if (props.autofocus) input.value?.focus({ preventScroll: true }); });
</script>

<template>
  <form class="unit-search" role="search" @submit.prevent="submit">
    <div class="input-group">
      <input ref="input" :id="inputId" v-model="q" class="form-control" type="search"
             placeholder="Search by name, e.g. RTX 3070, or enter a hash" autocomplete="off" spellcheck="false"
             role="combobox" aria-autocomplete="list" :aria-expanded="String(showList)" :aria-controls="listId"
             :aria-activedescendant="showList && active >= 0 ? listId + '-' + active : null"
             @focus="open = true" @blur="open = false; active = -1" @input="onInput" @keydown="onKey">
      <button class="btn px-4" :class="primary ? 'btn-rc' : 'btn-outline-info'" type="submit" :disabled="!query">
        <i class="fa-solid fa-magnifying-glass me-1"></i>Verify
      </button>
    </div>
    <ul v-show="showList" :id="listId" class="unit-suggest" role="listbox">
      <li v-if="!query && matches.length" class="unit-suggest-head" role="presentation">Recommended units</li>
      <li v-for="(u, i) in matches" :key="u.serial_hash" :id="listId + '-' + i" role="option" :aria-selected="i === active"
          class="unit-suggest-item" :class="{ active: i === active }" @mousedown.prevent="pick(u)" @mousemove="active = i">
        <span class="cart-thumb" :class="{ 'has-photo': u.photo }">
          <img v-if="u.photo" :src="u.photo" alt="" loading="lazy">
          <i v-else class="fa-solid" :class="u.icon"></i>
        </span>
        <span class="min-w-0 flex-grow-1">
          <span class="pick-name">{{ u.model_name }}</span>
          <span class="small text-muted-rc d-block text-truncate">{{ u.brand }} · {{ u.category }} · <span class="mono text-cyan">{{ u.serial_hash }}</span></span>
        </span>
        <span v-if="u.listing_price" class="small fw-semibold flex-shrink-0">{{ u.price }}</span>
      </li>
      <li v-if="note" class="unit-suggest-note" role="presentation">{{ note }}</li>
    </ul>
  </form>
</template>
