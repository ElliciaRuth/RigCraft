<script setup>
/**
 * Cart. The cart itself lives in this browser (lib/cart.js); this page
 * refreshes each unit's price and availability from the server.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { peso, pesoWhole, categoryIcon } from '@/lib/format.js';
import { cart, loadCart } from '@/lib/cart.js';
import { productPhoto } from '@/lib/photos.js';

const state = ref('loading');
const error = ref('');
const items = ref([]);
const priceChanged = ref(false);

const available   = computed(() => items.value.filter(i => i.available));
const unavailable = computed(() => items.value.filter(i => !i.available));
const total   = computed(() => available.value.reduce((s, i) => s + i.price, 0));
const savings = computed(() => available.value.reduce((s, i) => s + Math.max(0, (i.retail_price || 0) - i.price), 0));
const canCheckout = computed(() => available.value.length > 0 && !unavailable.value.length);

async function load() {
  try {
    const r = await loadCart();
    items.value = r.items;
    priceChanged.value = r.priceChanged;
    error.value = '';
  } catch (e) {
    // Show what's stored even if the server can't be reached.
    items.value = cart.items.value.map(i => ({ ...i, available: true, photo: productPhoto(i.model_name) }));
    error.value = e.message + ' Prices and availability could not be refreshed.';
  } finally {
    state.value = 'ready';
  }
}

function remove(hash) {
  cart.remove(hash);
  items.value = items.value.filter(i => i.hash !== hash);
}
function removeUnavailable() {
  unavailable.value.forEach(i => cart.remove(i.hash));
  items.value = items.value.filter(i => i.available);
}

// Another tab changed the cart: reload.
watch(() => cart.items.value.map(i => i.hash).join(','), (now) => {
  if (now !== items.value.map(i => i.hash).join(',')) load();
});
onMounted(load);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4 py-lg-5 shop-page">
      <h1 class="page-title mb-1">Your cart</h1>
      <p class="page-sub mb-4">Every unit is one tested, certified part, so each item is a single piece.</p>

      <div v-if="state === 'loading'" class="row g-4">
        <div class="col-lg-8"><div class="skeleton" style="height: 12rem"></div></div>
        <div class="col-lg-4"><div class="skeleton" style="height: 12rem"></div></div>
      </div>

      <div v-else-if="!items.length" class="rc-card empty-state">
        <i class="fa-solid fa-cart-shopping"></i>
        <p class="mt-3 mb-3">Your cart is empty.</p>
        <RouterLink :to="{ name: 'shop' }" class="btn btn-rc"><i class="fa-solid fa-bolt me-1"></i>Browse certified parts</RouterLink>
      </div>

      <div v-else class="row g-4 align-items-start">
        <div class="col-lg-8">
          <div v-if="error" class="alert alert-warning small">{{ error }}</div>
          <div v-if="unavailable.length" class="alert alert-warning d-flex flex-wrap align-items-center gap-2 small">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span class="me-auto">{{ unavailable.length === 1 ? 'One item has' : unavailable.length + ' items have' }} just sold or been taken off sale.</span>
            <button type="button" class="btn btn-sm btn-outline-warning" @click="removeUnavailable">Remove {{ unavailable.length === 1 ? 'it' : 'them' }}</button>
          </div>
          <div v-if="priceChanged" class="alert alert-info small"><i class="fa-solid fa-circle-info me-1"></i>A price changed since you added the item. The cart shows the current price.</div>

          <ul class="cart-list rc-card p-0">
            <li v-for="it in items" :key="it.hash" class="cart-item" :class="{ 'is-gone': !it.available }">
              <RouterLink class="cart-thumb" :class="{ 'has-photo': it.photo }" :to="{ name: 'verify', query: { hash: it.hash } }">
                <img v-if="it.photo" :src="it.photo" :alt="it.brand + ' ' + it.model_name" loading="lazy">
                <i v-else class="fa-solid" :class="categoryIcon(it.category)"></i>
              </RouterLink>
              <div class="cart-info">
                <div class="deal-meta">{{ it.brand }} · {{ it.category }}</div>
                <RouterLink class="cart-name" :to="{ name: 'verify', query: { hash: it.hash } }">{{ it.model_name }}</RouterLink>
                <div class="d-flex flex-wrap gap-2 mt-1">
                  <span class="mono small text-cyan">{{ it.hash }}</span>
                  <span v-if="it.available && it.warranty_months" class="fact"><i class="fa-solid fa-shield-halved me-1"></i>{{ it.warranty_months }}-mo warranty</span>
                  <span v-if="!it.available" class="tag fail">No longer available</span>
                </div>
              </div>
              <div class="cart-price">
                <div v-if="it.available && it.retail_price > it.price" class="deal-was">{{ pesoWhole(it.retail_price) }} new</div>
                <div class="fw-bold">{{ it.available ? pesoWhole(it.price) : '—' }}</div>
                <button type="button" class="btn btn-link btn-sm text-muted-rc p-0 mt-1" @click="remove(it.hash)" :aria-label="'Remove ' + it.model_name">
                  <i class="fa-solid fa-trash-can me-1"></i>Remove
                </button>
              </div>
            </li>
          </ul>
          <RouterLink :to="{ name: 'shop' }" class="btn btn-link px-0 mt-2 text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Keep shopping</RouterLink>
        </div>

        <div class="col-lg-4">
          <aside class="rc-card order-summary">
            <div class="rc-card-title mb-3">Order summary</div>
            <dl class="sum-rows">
              <div><dt>Items</dt><dd>{{ available.length }}</dd></div>
              <div v-if="savings > 0"><dt>You save vs brand-new</dt><dd class="text-emerald">{{ pesoWhole(savings) }}</dd></div>
              <div class="sum-total"><dt>Total</dt><dd>{{ peso(total) }}</dd></div>
            </dl>
            <p class="small text-muted-rc">Pay in cash or GCash when you pick up or receive your order. Nothing is charged online.</p>
            <RouterLink class="btn btn-rc w-100 py-2" :class="{ disabled: !canCheckout }" :aria-disabled="!canCheckout" :to="{ name: 'checkout' }">
              <i class="fa-solid fa-lock me-1"></i>Check out
            </RouterLink>
            <p v-if="unavailable.length" class="small text-warning text-center mt-2 mb-0">Remove unavailable items to check out.</p>
          </aside>
        </div>
      </div>
    </div>
  </main>
</template>
