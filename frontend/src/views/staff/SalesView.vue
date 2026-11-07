<script setup>
/** Staff: online orders from the store. Complete them on handover, or cancel to put the parts back on sale. */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/lib/api.js';
import { peso, fmtDateTime, debounce } from '@/lib/format.js';
import ModalDialog from '@/components/ModalDialog.vue';

const STATUS = {
  Placed:    { icon: 'fa-hourglass-half', tone: 'pending' },
  Completed: { icon: 'fa-circle-check',   tone: 'pass' },
  Cancelled: { icon: 'fa-circle-xmark',   tone: 'fail' },
};

const route  = useRoute();
const router = useRouter();
const status = ref(STATUS[route.query.status] ? route.query.status : '');
const q      = ref(String(route.query.q || ''));
const state  = ref('loading');
const error  = ref('');
const orders = ref([]);
const counts = ref({});
const flash  = ref('');

const pending    = ref(null);   // { order, status } awaiting confirmation
const busy       = ref(false);
const modalError = ref('');

const allCount = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0));

async function load() {
  // Keep filters in the URL so a refresh or a shared link shows the same list.
  const query = {};
  if (status.value) query.status = status.value;
  if (q.value.trim()) query.q = q.value.trim();
  router.replace({ query });

  try {
    const data = await api.get('orders', { status: status.value, q: q.value.trim() });
    orders.value = data.items;
    counts.value = data.counts;
    state.value = 'ready';
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
}

function ask(order, newStatus) {
  modalError.value = '';
  pending.value = { order, status: newStatus };
}

async function confirm() {
  const { order, status: newStatus } = pending.value;
  busy.value = true;
  modalError.value = '';
  try {
    const res = await api.post(`orders/${encodeURIComponent(order.order_no)}/status`, { status: newStatus });
    flash.value = newStatus === 'Cancelled'
      ? `Order ${order.order_no} cancelled. ${res.restocked} unit${res.restocked === 1 ? ' is' : 's are'} back on sale.`
      : `Order ${order.order_no} marked completed.`;
    pending.value = null;
    await load();
  } catch (e) {
    modalError.value = e.message;
  } finally {
    busy.value = false;
  }
}

const tel = (phone) => 'tel:' + String(phone).replace(/[^0-9+]/g, '');

watch(status, load);
watch(q, debounce(load, 300));
onMounted(load);
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
          <h1 class="page-title">Online orders</h1>
          <p class="page-sub mb-0">Units are marked <strong>Sold</strong> as soon as a shopper checks out. Cancelling an order puts its units back on sale.</p>
        </div>
        <input type="search" class="form-control" style="max-width: 18rem" v-model="q" placeholder="Search order, name, email, unit…" aria-label="Search orders">
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filter by order status">
        <button type="button" class="chip" :class="{ active: status === '' }" @click="status = ''">All <span class="count">{{ allCount }}</span></button>
        <button v-for="(m, s) in STATUS" :key="s" type="button" class="chip" :class="{ active: status === s }" @click="status = s">
          <i class="fa-solid" :class="m.icon"></i>{{ s }} <span class="count">{{ counts[s] || 0 }}</span>
        </button>
      </div>

      <div v-if="flash" class="alert alert-success py-2 small"><i class="fa-solid fa-circle-check me-1"></i>{{ flash }}</div>
      <div v-if="state === 'loading'" class="skeleton" style="height: 12rem"></div>
      <div v-else-if="state === 'error'" class="alert alert-warning">{{ error }}</div>
      <div v-else-if="!orders.length" class="rc-card empty-state">
        <i class="fa-solid fa-receipt"></i>
        <p class="mt-3 mb-0">{{ q || status ? 'No orders match.' : 'No online orders yet.' }}</p>
      </div>

      <div v-else class="d-flex flex-column gap-3">
        <article v-for="o in orders" :key="o.order_no" class="rc-card order-card">
          <header class="order-head">
            <div>
              <div class="mono fw-semibold">{{ o.order_no }}</div>
              <div class="small text-muted-rc">{{ fmtDateTime(o.created_at) }}<template v-if="o.status !== 'Placed'"> · {{ o.status.toLowerCase() }} {{ fmtDateTime(o.updated_at) }}</template></div>
            </div>
            <span class="status-pill" :class="STATUS[o.status].tone"><i class="fa-solid" :class="STATUS[o.status].icon"></i>{{ o.status }}</span>
          </header>

          <div class="row g-3">
            <div class="col-md-5">
              <dl class="kv kv-tight">
                <dt>Customer</dt><dd>{{ o.contact_name }}<div class="small text-muted-rc" v-if="o.customer_name !== o.contact_name">Account: {{ o.customer_name }}</div></dd>
                <dt>Mobile</dt><dd><a :href="tel(o.contact_phone)">{{ o.contact_phone }}</a></dd>
                <dt>Email</dt><dd><a :href="'mailto:' + o.customer_email">{{ o.customer_email }}</a></dd>
                <dt>Handover</dt><dd>{{ o.fulfillment === 'Delivery' ? 'Delivery' : 'Store pickup' }} · {{ o.payment_method }}</dd>
                <template v-if="o.address"><dt>Address</dt><dd>{{ o.address }}</dd></template>
                <template v-if="o.notes"><dt>Notes</dt><dd>{{ o.notes }}</dd></template>
              </dl>
            </div>
            <div class="col-md-7">
              <table class="table table-rc mb-2">
                <thead><tr><th>Unit</th><th>Hash</th><th class="text-end">Price</th></tr></thead>
                <tbody>
                  <tr v-for="it in o.items" :key="it.serial_hash">
                    <td><RouterLink :to="{ name: 'unit', params: { hash: it.serial_hash } }">{{ it.model_name }}</RouterLink><div class="small text-muted-rc">{{ it.brand }} · {{ it.category }}</div></td>
                    <td class="mono small">{{ it.serial_hash }}</td>
                    <td class="text-end">{{ peso(it.price) }}</td>
                  </tr>
                </tbody>
                <tfoot><tr><th colspan="2" class="text-end">Total</th><th class="text-end fs-6 text-white">{{ peso(o.total) }}</th></tr></tfoot>
              </table>
              <div v-if="o.status === 'Placed'" class="d-flex flex-wrap justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-outline-danger" @click="ask(o, 'Cancelled')"><i class="fa-solid fa-xmark me-1"></i>Cancel order</button>
                <button type="button" class="btn btn-sm btn-emerald" @click="ask(o, 'Completed')"><i class="fa-solid fa-check me-1"></i>Mark completed</button>
              </div>
            </div>
          </div>
        </article>
      </div>

      <ModalDialog :show="!!pending" :title="pending && pending.status === 'Cancelled' ? 'Cancel order?' : 'Complete order?'" :busy="busy" @close="pending = null">
        <template v-if="pending">
          <p v-if="pending.status === 'Cancelled'" class="mb-0">
            Order <strong class="mono">{{ pending.order.order_no }}</strong> is cancelled and its
            {{ pending.order.items.length === 1 ? 'unit goes' : pending.order.items.length + ' units go' }} back to <strong>Certified</strong> stock, on sale again.
          </p>
          <p v-else class="mb-0">
            Confirm that <strong>{{ pending.order.contact_name }}</strong> received order <strong class="mono">{{ pending.order.order_no }}</strong>
            and paid <strong>{{ peso(pending.order.total) }}</strong> by {{ pending.order.payment_method }}.
          </p>
        </template>
        <div v-if="modalError" class="alert alert-danger py-2 small mt-3 mb-0">{{ modalError }}</div>
        <template #footer>
          <button class="btn btn-outline-secondary" :disabled="busy" @click="pending = null">Back</button>
          <button v-if="pending && pending.status === 'Cancelled'" class="btn btn-danger" :disabled="busy" @click="confirm">
            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Cancel order</button>
          <button v-else class="btn btn-emerald" :disabled="busy" @click="confirm">
            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Mark completed</button>
        </template>
      </ModalDialog>
    </div>
  </main>
</template>
