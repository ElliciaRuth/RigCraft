<script setup>
/**
 * Store layout: sticky nav (solid once scrolled, reading-progress line,
 * section highlighting on the home page) + footer.
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { session } from '@/lib/session.js';
import { cart } from '@/lib/cart.js';
import { authCall } from '@/lib/api.js';

const route  = useRoute();
const router = useRouter();

const links = [
  { key: 'home',   to: { name: 'home' },                 label: 'Home',          icon: 'fa-house' },
  { key: 'deals',  to: { name: 'home', hash: '#deals' }, label: 'Deals',         icon: 'fa-tags' },
  { key: 'why',    to: { name: 'home', hash: '#why' },   label: 'Why RigCraft',  icon: 'fa-award' },
  { key: 'how',    to: { name: 'home', hash: '#how' },   label: 'How we test',   icon: 'fa-list-check' },
  { key: 'shop',   to: { name: 'shop' },                 label: 'Shop',          icon: 'fa-store' },
  { key: 'verify', to: { name: 'verify' },               label: 'Verify a unit', icon: 'fa-shield-halved' },
];

const ceiling  = computed(() => session.rules.temp_ceiling_celsius.toFixed(1));
const warranty = computed(() => session.rules.warranty_months);
const firstName = computed(() => (session.customer?.full_name || '').split(' ')[0]);

// ---------------- Scroll: solid nav + progress line + home scrollspy ----------------
const scrolled = ref(false);
const progress = ref(0);
const spy      = ref('home');   // section in view on the home page
let queued = false;

function update() {
  queued = false;
  const y = window.scrollY, max = document.documentElement.scrollHeight - window.innerHeight;
  scrolled.value = y > 8;
  progress.value = max > 0 ? Math.min(1, y / max) * 100 : 0;

  if (route.name === 'home') {
    // A section counts as current once its top passes a line ~30% down the visible area.
    const line = 64 + window.innerHeight * 0.3;
    let current = 'home';
    for (const id of ['deals', 'why', 'how']) {
      const el = document.getElementById(id);
      if (el && el.getBoundingClientRect().top <= line) current = id;
    }
    spy.value = current;
  }
}
const onScroll = () => { if (!queued) { queued = true; requestAnimationFrame(update); } };

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  update();
});
onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll);
  window.removeEventListener('resize', onScroll);
});
watch(() => route.fullPath, () => nextTick(update));

const activeKey = computed(() => (route.name === 'home' ? spy.value : route.meta.nav));

// Close the phone menu after navigating.
const collapse = ref(null);
watch(() => route.fullPath, () => collapse.value?.classList.remove('show'));

async function signOut() {
  await authCall('auth/customer/logout');
  router.push({ name: 'signin', query: { bye: 1 } });
}
</script>

<template>
  <nav class="navbar navbar-expand-md rc-nav sticky-top" :class="{ scrolled }" :style="{ '--progress': progress + '%' }">
    <div class="scroll-progress" aria-hidden="true"></div>
    <div class="container-wide">
      <RouterLink class="navbar-brand rc-logo" :to="{ name: 'home' }"><i class="fa-solid fa-microchip me-1"></i>Rig<span>Craft</span></RouterLink>

      <!-- Phones: cart stays visible next to the menu button -->
      <RouterLink v-if="session.canShop" class="nav-cart d-md-none ms-auto me-2" :class="{ active: route.meta.nav === 'cart' }" :to="{ name: 'cart' }" aria-label="Cart">
        <i class="fa-solid fa-cart-shopping"></i><span v-if="cart.count.value" class="cart-count">{{ cart.count.value }}</span>
      </RouterLink>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav"
              aria-controls="publicNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div ref="collapse" class="collapse navbar-collapse" id="publicNav">
        <ul class="navbar-nav public-links ms-auto mb-2 mb-md-0">
          <li v-for="l in links" :key="l.key" class="nav-item">
            <RouterLink class="nav-link" :class="{ active: activeKey === l.key }" :to="l.to"
                        :aria-current="activeKey === l.key ? (route.name === 'home' && l.key !== 'home' ? 'location' : 'page') : null">
              <i class="fa-solid me-2 d-md-none" :class="l.icon"></i>{{ l.label }}
            </RouterLink>
          </li>
        </ul>
        <span class="nav-divider d-none d-md-block"></span>
        <div class="d-flex flex-wrap align-items-center gap-2 pb-2 pb-md-0">
          <RouterLink v-if="session.canShop" class="nav-cart d-none d-md-inline-flex" :class="{ active: route.meta.nav === 'cart' }" :to="{ name: 'cart' }" aria-label="Cart">
            <i class="fa-solid fa-cart-shopping"></i><span v-if="cart.count.value" class="cart-count">{{ cart.count.value }}</span>
          </RouterLink>

          <div v-if="session.customer" class="dropdown">
            <button type="button" class="btn btn-sm btn-ghost dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fa-solid fa-circle-user me-1"></i>{{ firstName }}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li class="px-3 py-2">
                <div class="fw-semibold">{{ session.customer.full_name }}</div>
                <div class="small text-muted-rc">{{ session.customer.email }}</div>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li><RouterLink class="dropdown-item" :class="{ active: route.meta.nav === 'orders' }" :to="{ name: 'orders' }"><i class="fa-solid fa-receipt me-2"></i>My orders</RouterLink></li>
              <li><button type="button" class="dropdown-item" @click="signOut"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</button></li>
            </ul>
          </div>
          <RouterLink v-else-if="!session.user" class="btn btn-sm btn-outline-info" :to="{ name: 'signin' }">
            <i class="fa-solid fa-right-to-bracket me-1"></i>Sign in
          </RouterLink>
          <RouterLink v-if="session.user" class="btn btn-sm btn-rc" :to="{ name: 'dashboard' }">
            <i class="fa-solid fa-gauge-high me-1"></i>Staff dashboard
          </RouterLink>
        </div>
      </div>
    </div>
  </nav>

  <slot />

  <footer class="site-footer mt-auto">
    <div class="container-wide py-5">
      <div class="row g-4">
        <div class="col-md-5">
          <div class="rc-logo fs-5 mb-2"><i class="fa-solid fa-microchip me-1"></i>Rig<span>Craft</span></div>
          <p class="small mb-0">Certified pre-owned PC components. Every unit is cleaned, re-pasted and stress-tested
            under a strict {{ ceiling }} °C ceiling before it's listed.</p>
        </div>
        <div class="col-6 col-md-3">
          <div class="footer-title">Shop</div>
          <ul class="list-unstyled small mb-0">
            <li><RouterLink :to="{ name: 'shop' }">Shop all parts</RouterLink></li>
            <li><RouterLink :to="{ name: 'home', hash: '#deals' }">Today's deals</RouterLink></li>
            <li><RouterLink :to="{ name: 'home', hash: '#why' }">Why RigCraft</RouterLink></li>
            <li><RouterLink :to="{ name: 'home', hash: '#how' }">How we test</RouterLink></li>
            <li><RouterLink :to="{ name: 'verify' }">Verify a unit</RouterLink></li>
            <li v-if="session.canShop"><RouterLink :to="{ name: 'cart' }">Your cart</RouterLink></li>
            <li v-if="session.customer"><RouterLink :to="{ name: 'orders' }">My orders</RouterLink></li>
            <li v-else-if="!session.user"><RouterLink :to="{ name: 'signin' }">Sign in to shop</RouterLink></li>
          </ul>
        </div>
        <div class="col-6 col-md-4">
          <div class="footer-title">Our promise</div>
          <ul class="list-unstyled small mb-0">
            <li><i class="fa-solid fa-temperature-half me-2"></i>≤ {{ ceiling }} °C under 30-min load</li>
            <li><i class="fa-solid fa-memory me-2"></i>Zero memory errors</li>
            <li><i class="fa-solid fa-shield-halved me-2"></i>{{ warranty }}-month warranty</li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom small mt-4 pt-3 d-flex flex-wrap justify-content-between gap-2">
        <span>© {{ new Date().getFullYear() }} RigCraft. All prices in Philippine pesos (₱).</span>
        <RouterLink :to="{ name: 'staff-login' }">Staff login</RouterLink>
      </div>
    </div>
  </footer>
</template>
