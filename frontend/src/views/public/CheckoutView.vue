<script setup>
/**
 * Checkout: contact, pickup or delivery, payment on handover. The server
 * re-checks every unit, records the order and marks the units Sold.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { cart, loadCart } from '@/lib/cart.js';
import { peso, pesoWhole, categoryIcon } from '@/lib/format.js';

const FULFILLMENT = [
  { value: 'Pickup',   label: 'Store pickup', icon: 'fa-store', hint: "We'll text you when it's ready" },
  { value: 'Delivery', label: 'Delivery',     icon: 'fa-truck', hint: 'Fee depends on your area' },
];
const PAYMENT = [
  { value: 'Cash',  label: 'Cash',  icon: 'fa-money-bill-wave', hintPickup: 'Pay at the counter',    hintDelivery: 'Cash on delivery' },
  { value: 'GCash', label: 'GCash', icon: 'fa-mobile-screen',   hintPickup: 'Scan our QR on pickup', hintDelivery: 'Send on delivery' },
];

const router = useRouter();
const me     = computed(() => session.customer || {});
const state  = ref('loading');   // loading | ready | error
const error  = ref('');
const notice = ref('');
const busy   = ref(false);
const items  = ref([]);
const fieldErrors = ref({});

const form = reactive({
  contact_name:   me.value.full_name || '',
  contact_phone:  me.value.phone || '',
  fulfillment:    'Pickup',
  address:        '',
  payment_method: 'Cash',
  notes:          '',
});

const total   = computed(() => items.value.reduce((s, i) => s + i.price, 0));
const savings = computed(() => items.value.reduce((s, i) => s + Math.max(0, (i.retail_price || 0) - i.price), 0));
const err = (k) => fieldErrors.value[k] || '';

/** Load the cart; anything no longer for sale is dropped with a notice. */
async function refresh(extraNotice = '') {
  const r = await loadCart();
  const gone = r.items.filter(i => !i.available);
  gone.forEach(i => cart.remove(i.hash));
  items.value = r.items.filter(i => i.available);

  const notes = [];
  if (extraNotice) notes.push(extraNotice);
  else if (gone.length) notes.push(`${gone.map(i => i.model_name).join(', ')} just sold, so we removed ${gone.length === 1 ? 'it' : 'them'} from your cart.`);
  if (r.priceChanged && !extraNotice) notes.push('A price changed since you added the item. The total below is the current price.');
  notice.value = notes.join(' ');
}

async function placeOrder() {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  fieldErrors.value = {};
  try {
    const res = await api.post('checkout', { ...form, items: items.value.map(i => i.hash), expected_total: total.value });
    cart.clear();
    router.push({ name: 'orders', query: { placed: res.order_no } });
    return;   // keep the button busy while the page changes
  } catch (e) {
    if (e.fields) {
      fieldErrors.value = e.fields;
      error.value = 'Please fix the highlighted fields.';
    } else if (e.data?.unavailable || e.data?.price_changed) {
      // Someone else bought a unit, or a price changed: refresh and let the shopper review.
      try { await refresh(e.message); } catch (e2) { error.value = e2.message; }
      if (items.value.length) window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      error.value = e.message;
    }
  }
  busy.value = false;
}

onMounted(async () => {
  try {
    await refresh();
    state.value = 'ready';
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
});
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4 py-lg-5 shop-page">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
          <li class="breadcrumb-item"><RouterLink :to="{ name: 'cart' }">Cart</RouterLink></li>
          <li class="breadcrumb-item active" aria-current="page">Checkout</li>
        </ol>
      </nav>
      <h1 class="page-title mb-1">Checkout</h1>
      <p class="page-sub mb-4">Signed in as {{ me.full_name }} ({{ me.email }})</p>

      <div v-if="state === 'loading'" class="row g-4">
        <div class="col-lg-7"><div class="skeleton" style="height: 20rem"></div></div>
        <div class="col-lg-5"><div class="skeleton" style="height: 14rem"></div></div>
      </div>

      <div v-else-if="state === 'error'" class="alert alert-warning">{{ error }}</div>

      <div v-else-if="!items.length" class="rc-card empty-state">
        <i class="fa-solid fa-cart-shopping"></i>
        <p class="mt-3 mb-3">{{ notice || 'Your cart is empty.' }}</p>
        <RouterLink :to="{ name: 'shop' }" class="btn btn-rc"><i class="fa-solid fa-bolt me-1"></i>Browse certified parts</RouterLink>
      </div>

      <form v-else class="row g-4 align-items-start" novalidate @submit.prevent="placeOrder">
        <div class="col-lg-7">
          <div v-if="notice" class="alert alert-warning small"><i class="fa-solid fa-triangle-exclamation me-1"></i>{{ notice }}</div>

          <section class="rc-card mb-3">
            <div class="rc-card-title mb-3"><span class="step-dot">1</span>Contact</div>
            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="c-name">Name for the order</label>
                <input class="form-control" id="c-name" v-model.trim="form.contact_name" :class="{ 'is-invalid': err('contact_name') }" autocomplete="name" maxlength="80">
                <div class="invalid-feedback">{{ err('contact_name') }}</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="c-phone">Mobile number</label>
                <input class="form-control" id="c-phone" type="tel" v-model.trim="form.contact_phone" :class="{ 'is-invalid': err('contact_phone') }" autocomplete="tel" maxlength="20" placeholder="0917 123 4567">
                <div class="invalid-feedback">{{ err('contact_phone') }}</div>
              </div>
            </div>
            <p class="small text-muted-rc mt-2 mb-0">We'll text this number to confirm your order.</p>
          </section>

          <section class="rc-card mb-3">
            <div class="rc-card-title mb-3"><span class="step-dot">2</span>How you'll get it</div>
            <div class="row g-2" role="radiogroup" aria-label="Pickup or delivery">
              <div class="col-sm-6" v-for="o in FULFILLMENT" :key="o.value">
                <label class="option-card" :class="{ active: form.fulfillment === o.value }">
                  <input type="radio" class="visually-hidden" name="fulfillment" :value="o.value" v-model="form.fulfillment">
                  <i class="fa-solid" :class="o.icon"></i>
                  <span><strong>{{ o.label }}</strong><small>{{ o.hint }}</small></span>
                </label>
              </div>
            </div>
            <div v-if="form.fulfillment === 'Delivery'" class="mt-3">
              <label class="form-label" for="c-address">Delivery address</label>
              <textarea class="form-control" id="c-address" rows="3" v-model.trim="form.address" :class="{ 'is-invalid': err('address') }" maxlength="300"
                        autocomplete="street-address" placeholder="House no., street, barangay, city, province"></textarea>
              <div class="invalid-feedback">{{ err('address') }}</div>
            </div>
          </section>

          <section class="rc-card mb-3">
            <div class="rc-card-title mb-3"><span class="step-dot">3</span>Payment</div>
            <div class="row g-2" role="radiogroup" aria-label="Payment method">
              <div class="col-sm-6" v-for="o in PAYMENT" :key="o.value">
                <label class="option-card" :class="{ active: form.payment_method === o.value }">
                  <input type="radio" class="visually-hidden" name="payment" :value="o.value" v-model="form.payment_method">
                  <i class="fa-solid" :class="o.icon"></i>
                  <span><strong>{{ o.label }}</strong><small>{{ form.fulfillment === 'Delivery' ? o.hintDelivery : o.hintPickup }}</small></span>
                </label>
              </div>
            </div>
            <p class="small text-muted-rc mt-2 mb-0"><i class="fa-solid fa-lock me-1"></i>Nothing is charged online. You pay when you get the parts.</p>
          </section>

          <section class="rc-card">
            <label class="form-label rc-card-title" for="c-notes">Notes (optional)</label>
            <textarea class="form-control" id="c-notes" rows="2" v-model.trim="form.notes" :class="{ 'is-invalid': err('notes') }" maxlength="500"
                      placeholder="Anything we should know, e.g. best time to call"></textarea>
            <div class="invalid-feedback">{{ err('notes') }}</div>
          </section>
        </div>

        <div class="col-lg-5">
          <aside class="rc-card order-summary">
            <div class="rc-card-title mb-3">Your order · {{ items.length }} item{{ items.length === 1 ? '' : 's' }}</div>
            <ul class="mini-items">
              <li v-for="it in items" :key="it.hash">
                <span class="mini-thumb" :class="{ 'has-photo': it.photo }">
                  <img v-if="it.photo" :src="it.photo" alt="">
                  <i v-else class="fa-solid" :class="categoryIcon(it.category)"></i>
                </span>
                <span class="mini-name">{{ it.model_name }}<small class="mono">{{ it.hash }}</small></span>
                <span class="fw-semibold">{{ pesoWhole(it.price) }}</span>
              </li>
            </ul>
            <dl class="sum-rows">
              <div v-if="savings > 0"><dt>You save vs brand-new</dt><dd class="text-emerald">{{ pesoWhole(savings) }}</dd></div>
              <div><dt>{{ form.fulfillment === 'Delivery' ? 'Delivery' : 'Store pickup' }}</dt><dd>{{ form.fulfillment === 'Delivery' ? 'Fee confirmed by text' : 'Free' }}</dd></div>
              <div class="sum-total"><dt>Total</dt><dd>{{ peso(total) }}</dd></div>
            </dl>
            <div v-if="error" class="alert alert-danger py-2 small" role="alert">{{ error }}</div>
            <button type="submit" class="btn btn-rc w-100 py-2" :disabled="busy">
              <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="fa-solid fa-check me-1"></i>Place order · {{ pesoWhole(total) }}
            </button>
            <p class="small text-muted-rc text-center mt-2 mb-0">The parts are reserved for you as soon as you place the order.</p>
          </aside>
        </div>
      </form>
    </div>
  </main>
</template>
