<script setup>
/** The shopper's orders. Checkout lands here with ?placed=<order no>. Placed orders can be cancelled. */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { peso, pesoWhole, fmtDateTime, categoryIcon } from '@/lib/format.js';
import { productPhoto } from '@/lib/photos.js';
import ModalDialog from '@/components/ModalDialog.vue';

/** Order status -> label, icon and pill tone. */
const STATUS = {
  Placed:    { label: 'Placed · Awaiting handover', icon: 'fa-hourglass-half', tone: 'pending' },
  Completed: { label: 'Completed',                  icon: 'fa-circle-check',   tone: 'pass' },
  Cancelled: { label: 'Cancelled',                  icon: 'fa-circle-xmark',   tone: 'fail' },
};

const route  = useRoute();
const me     = computed(() => session.customer || {});
const placed = String(route.query.placed || '').toUpperCase();
const state  = ref('loading');
const error  = ref('');
const orders = ref([]);
const flash  = ref('');

// Thank-you banner only while that order is still open.
const placedOrder = computed(() => orders.value.find(o => o.order_no === placed && o.status === 'Placed') || null);
const tone  = (s) => STATUS[s]?.tone || 'pending';
const photo = (it) => productPhoto(it.model_name);

async function load() {
  try {
    orders.value = (await api.get('my/orders')).items;
    state.value = 'ready';
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
}

// ---------------- Cancel (only while Placed) ----------------
const cancelling = ref(null);      // order awaiting confirmation
const busy       = ref(false);
const modalError = ref('');

function askCancel(order) {
  modalError.value = '';
  cancelling.value = order;
}

async function confirmCancel() {
  const order = cancelling.value;
  busy.value = true;
  modalError.value = '';
  try {
    await api.post(`my/orders/${encodeURIComponent(order.order_no)}/cancel`);
    flash.value = `Order ${order.order_no} is cancelled. You haven't been charged.`;
    cancelling.value = null;
    await load();
  } catch (e) {
    modalError.value = e.message;
    if (e.status === 409) load();   // already handed over/cancelled: show the real status
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4 py-lg-5 shop-page">
      <div v-if="placedOrder" class="order-placed rc-card mb-4" role="status">
        <div class="order-placed-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div>
          <h1 class="h4 fw-bold mb-1">Thank you! Your order is placed.</h1>
          <p class="mb-0 text-muted-rc">Order <strong class="mono text-cyan">{{ placed }}</strong>. We'll text you to confirm
            {{ placedOrder.fulfillment === 'Delivery' ? 'delivery' : "when it's ready for pickup" }}. The parts are reserved for you.</p>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
          <component :is="placedOrder ? 'h2' : 'h1'" class="page-title">My orders</component>
          <p class="page-sub mb-0">{{ me.full_name }} · {{ me.email }}</p>
        </div>
        <RouterLink :to="{ name: 'shop' }" class="btn btn-ghost btn-sm"><i class="fa-solid fa-bolt me-1"></i>Keep shopping</RouterLink>
      </div>

      <div v-if="flash" class="alert alert-success d-flex align-items-center py-2 small" role="status">
        <i class="fa-solid fa-circle-check me-2"></i><span class="flex-grow-1">{{ flash }}</span>
        <button type="button" class="btn-close btn-close-white ms-2" aria-label="Dismiss" @click="flash = ''"></button>
      </div>

      <div v-if="state === 'loading'"><div class="skeleton" style="height: 10rem"></div></div>
      <div v-else-if="state === 'error'" class="alert alert-warning">{{ error }}</div>
      <div v-else-if="!orders.length" class="rc-card empty-state">
        <i class="fa-solid fa-receipt"></i>
        <p class="mt-3 mb-3">You haven't placed any orders yet.</p>
        <RouterLink :to="{ name: 'shop' }" class="btn btn-rc">Browse certified parts</RouterLink>
      </div>

      <div v-else class="d-flex flex-column gap-3">
        <article v-for="o in orders" :key="o.order_no" class="rc-card order-card" :class="{ 'is-new': o.order_no === placed }">
          <header class="order-head">
            <div>
              <div class="mono fw-semibold">{{ o.order_no }}</div>
              <div class="small text-muted-rc">Placed {{ fmtDateTime(o.created_at) }}</div>
            </div>
            <span class="status-pill" :class="tone(o.status)"><i class="fa-solid" :class="STATUS[o.status].icon"></i>{{ STATUS[o.status].label }}</span>
          </header>
          <ul class="mini-items mb-3">
            <li v-for="it in o.items" :key="it.serial_hash">
              <span class="mini-thumb" :class="{ 'has-photo': photo(it) }">
                <img v-if="photo(it)" :src="photo(it)" alt="">
                <i v-else class="fa-solid" :class="categoryIcon(it.category)"></i>
              </span>
              <span class="mini-name">
                <RouterLink :to="{ name: 'verify', query: { hash: it.serial_hash } }">{{ it.model_name }}</RouterLink>
                <small class="mono">{{ it.serial_hash }} · test report</small>
              </span>
              <span class="fw-semibold">{{ pesoWhole(it.price) }}</span>
            </li>
          </ul>
          <div class="order-foot">
            <span><i class="fa-solid me-1" :class="o.fulfillment === 'Delivery' ? 'fa-truck' : 'fa-store'"></i>{{ o.fulfillment === 'Delivery' ? 'Delivery' : 'Store pickup' }}</span>
            <span><i class="fa-solid fa-wallet me-1"></i>{{ o.payment_method }} on {{ o.fulfillment === 'Delivery' ? 'delivery' : 'pickup' }}</span>
            <span v-if="o.address" class="text-truncate" style="max-width: 22rem" :title="o.address"><i class="fa-solid fa-location-dot me-1"></i>{{ o.address }}</span>
            <span class="ms-auto fw-bold fs-6">{{ peso(o.total) }}</span>
          </div>
          <div v-if="o.status === 'Placed'" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
            <span class="small text-muted-rc">Changed your mind? You can cancel until the order is handed over.</span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="askCancel(o)">
              <i class="fa-solid fa-xmark me-1"></i>Cancel order
            </button>
          </div>
        </article>
      </div>

      <ModalDialog :show="!!cancelling" title="Cancel this order?" :busy="busy" @close="cancelling = null">
        <template v-if="cancelling">
          <p class="mb-2">
            Order <strong class="mono">{{ cancelling.order_no }}</strong> ({{ peso(cancelling.total) }}) will be cancelled and
            {{ cancelling.items.length === 1 ? 'its part goes' : 'its ' + cancelling.items.length + ' parts go' }} back on sale.
          </p>
          <p class="small text-muted-rc mb-0">Nothing has been charged — you pay on {{ cancelling.fulfillment === 'Delivery' ? 'delivery' : 'pickup' }}.
            If you change your mind later, you'll need to order again (if the part is still available).</p>
        </template>
        <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
        <template #footer>
          <button class="btn btn-outline-secondary" :disabled="busy" @click="cancelling = null">Keep order</button>
          <button class="btn btn-danger" :disabled="busy" @click="confirmCancel">
            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Cancel order</button>
        </template>
      </ModalDialog>
    </div>
  </main>
</template>
