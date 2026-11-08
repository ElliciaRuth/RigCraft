<script setup>
/**
 * Storefront home: hero with an animated chip, brand ticker, count-up stats,
 * today's deals, complete rigs, "marketplace vs RigCraft", how we test, call to action.
 * Motion is skipped for visitors who prefer reduced motion.
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { useShopCart } from '@/lib/useShopCart.js';
import DealCard from '@/components/DealCard.vue';
import CountUp from '@/components/CountUp.vue';
import CartToast from '@/components/CartToast.vue';
import RigShowcase from '@/components/RigShowcase.vue';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const route    = useRoute();
const ceiling  = computed(() => session.rules.temp_ceiling_celsius);
const warranty = computed(() => session.rules.warranty_months);
const guest    = computed(() => !session.user && !session.customer);

// Decorative circuit traces for the hero chip: [path, pad x, pad y]
const traces = [
  ['M190 170 V120 H120 V70', 120, 70],  ['M230 170 V58', 230, 58],            ['M270 170 V110 H340 V62', 340, 62],
  ['M310 200 H380 V150 H430', 430, 150], ['M310 240 H442', 442, 240],           ['M310 280 H370 V350 H422', 422, 350],
  ['M290 310 V380 H360 V422', 360, 422], ['M250 310 V432', 250, 432],           ['M210 310 V360 H130 V412', 130, 412],
  ['M170 280 H100 V330 H48', 48, 330],   ['M170 240 H38', 38, 240],             ['M170 200 H110 V140 H58', 58, 140],
];
const pins   = Array.from({ length: 6 }, (_, k) => 186 + k * 22);
const brands = ['NVIDIA', 'AMD', 'Intel', 'ASUS', 'MSI', 'Gigabyte', 'Sapphire', 'Zotac', 'EVGA', 'Corsair', 'Kingston', 'Samsung'];

// ---------------- Deals ----------------
const state = ref('loading');
const error = ref('');
const items = ref([]);
const stats = ref(null);
const DEALS_SHOWN = 10;   // biggest savings first; the full catalogue (with filters) is /shop
const visible = computed(() =>
  [...items.value].sort((a, b) => (b.savings_pct ?? -1) - (a.savings_pct ?? -1)).slice(0, DEALS_SHOWN));
const bestPct = computed(() => stats.value?.max_savings_pct || 0);
const { canShop, inCart, addToCart, cartNotice } = useShopCart();

// ---------------- Rotating headline word ----------------
const words = ['graphics cards', 'processors', 'memory kits', 'PC parts'];
const wordIndex = ref(0);
const word = computed(() => words[wordIndex.value]);
let wordTimer = 0;

// ---------------- Hero parallax ----------------
const px = ref(0), py = ref(0);
function parallax(e) {
  if (reduceMotion || window.innerWidth < 992) return;
  const r = e.currentTarget.getBoundingClientRect();
  px.value = ((e.clientX - r.left) / r.width - 0.5) * 2;
  py.value = ((e.clientY - r.top) / r.height - 0.5) * 2;
}
const resetParallax = () => { px.value = 0; py.value = 0; };

// ---------------- Scroll reveal ----------------
let revealer = null;
function observeReveals() {
  const targets = document.querySelectorAll('.reveal:not(.in)');
  if (reduceMotion || !('IntersectionObserver' in window)) {
    targets.forEach(el => el.classList.add('in'));
    return;
  }
  revealer ??= new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) { entry.target.classList.add('in'); revealer.unobserve(entry.target); }
    }
  }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
  targets.forEach(el => revealer.observe(el));
}

onMounted(async () => {
  if (!reduceMotion) document.documentElement.classList.add('js-anim');
  observeReveals();
  if (!reduceMotion) wordTimer = setInterval(() => { wordIndex.value = (wordIndex.value + 1) % words.length; }, 2400);

  try {
    const data = await api.get('shop');
    items.value = data.items;
    stats.value = data.stats;
    state.value = 'ready';
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
  await nextTick();
  observeReveals();

  // The router scrolls to a #section before the deals load, and the deals then
  // push everything below them down. Scroll to the section again once they're in
  // (e.g. "Rigs" in the nav clicked from the shop).
  const target = route.hash ? document.getElementById(route.hash.slice(1)) : null;
  target?.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth' });
});
watch(visible, () => nextTick(observeReveals));

onBeforeUnmount(() => {
  clearInterval(wordTimer);
  revealer?.disconnect();
  document.documentElement.classList.remove('js-anim');
});
</script>

<template>
  <main class="flex-grow-1 home">

    <!-- ===================== HERO ===================== -->
    <section class="hero2" @mousemove="parallax" @mouseleave="resetParallax">
      <div class="hero-bg" aria-hidden="true">
        <div class="grid"></div>
        <span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span>
      </div>

      <div class="container-wide position-relative">
        <div class="row align-items-center gx-5 gy-4">
          <div class="col-lg-6 col-xl-5">
            <div class="live-pill reveal">
              <span class="live-dot"></span>
              <span v-if="state === 'ready'">{{ items.length }} certified parts in stock now</span>
              <span v-else>Certified parts in stock now</span>
            </div>

            <h1 class="display-hero reveal" style="--d:1">
              Flagship performance.<br><span class="grad-text">Second-hand price.</span>
            </h1>

            <p class="lead-hero reveal" style="--d:2">
              Pre-owned
              <span class="rotator"><span :key="word" class="word-in">{{ word }}</span></span>
              that are deep-cleaned, re-pasted and stress-tested before we sell them, so you get
              the speed you want <strong class="text-white" v-if="stats">for up to {{ stats.max_savings_pct }}% less than brand-new.</strong>
              <strong class="text-white" v-else>for a lot less than brand-new.</strong>
            </p>

            <div class="d-flex flex-wrap gap-3 mt-4 reveal" style="--d:3">
              <RouterLink :to="{ hash: '#deals' }" class="btn btn-rc btn-glow px-4 py-2"><i class="fa-solid fa-bolt me-2"></i>Shop today's deals</RouterLink>
              <RouterLink :to="{ hash: '#how' }" class="btn btn-ghost px-4 py-2">How we test</RouterLink>
            </div>

            <div class="trust-row reveal" style="--d:4">
              <span><i class="fa-solid fa-circle-check"></i>30-min stress test</span>
              <span><i class="fa-solid fa-circle-check"></i>{{ warranty }}-month warranty</span>
              <span><i class="fa-solid fa-circle-check"></i>QR-verified history</span>
            </div>
          </div>

          <!-- Animated chip -->
          <div class="col-lg-6 col-xl-7">
            <div class="hero-visual reveal" style="--d:2" :style="{ '--px': px, '--py': py }" aria-hidden="true">
              <svg class="chip-svg" viewBox="0 0 480 480">
                <defs>
                  <linearGradient id="rcGrad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#06B6D4"/><stop offset="1" stop-color="#10B981"/>
                  </linearGradient>
                  <linearGradient id="dieGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#1b2a44"/><stop offset="1" stop-color="#0d1526"/>
                  </linearGradient>
                  <linearGradient id="scanGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#06B6D4" stop-opacity="0"/>
                    <stop offset=".5" stop-color="#06B6D4" stop-opacity=".35"/>
                    <stop offset="1" stop-color="#06B6D4" stop-opacity="0"/>
                  </linearGradient>
                  <clipPath id="dieClip"><rect x="192" y="192" width="96" height="96" rx="14"/></clipPath>
                </defs>

                <template v-for="([d, x, y], i) in traces" :key="i">
                  <path class="trace" :d="d"/>
                  <path class="pulse" :d="d" pathLength="1" :style="{ animationDelay: ((i % 6) * 0.55).toFixed(2) + 's' }"/>
                  <circle class="pad" :class="{ lit: i % 3 === 0 }" :cx="x" :cy="y" r="6" :style="{ animationDelay: ((i % 6) * 0.55).toFixed(2) + 's' }"/>
                </template>

                <circle class="ring r1" cx="240" cy="240" r="118"/>
                <circle class="ring r2" cx="240" cy="240" r="142"/>

                <template v-for="p in pins" :key="p">
                  <rect class="pin" :x="p" y="158" width="8" height="12" rx="2"/>
                  <rect class="pin" :x="p" y="310" width="8" height="12" rx="2"/>
                  <rect class="pin" x="158" :y="p" width="12" height="8" rx="2"/>
                  <rect class="pin" x="310" :y="p" width="12" height="8" rx="2"/>
                </template>

                <rect class="chip-body" x="170" y="170" width="140" height="140" rx="22"/>
                <rect x="192" y="192" width="96" height="96" rx="14" fill="url(#dieGrad)"/>
                <rect class="scan" x="192" y="150" width="96" height="40" fill="url(#scanGrad)" clip-path="url(#dieClip)"/>
                <text class="chip-text" x="240" y="252" text-anchor="middle">RC</text>
                <text class="chip-sub" x="240" y="274" text-anchor="middle">CERTIFIED</text>
              </svg>

              <div class="float-chip f1"><i class="fa-solid fa-temperature-half"></i>≤ {{ ceiling.toFixed(1) }} °C under load</div>
              <div class="float-chip f2"><i class="fa-solid fa-shield-halved text-emerald"></i>{{ warranty }}-month warranty</div>
              <div class="float-chip f3"><i class="fa-solid fa-tags text-emerald"></i>
                <span v-if="stats">Up to {{ stats.max_savings_pct }}% off new</span><span v-else>Below brand-new prices</span>
              </div>
              <div class="float-chip f4"><i class="fa-solid fa-qrcode"></i>Scan to verify</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== BRAND MARQUEE ===================== -->
    <section class="marquee" aria-label="Brands we stock">
      <div class="marquee-track">
        <div v-for="copy in 2" :key="copy" class="marquee-group" :aria-hidden="copy === 2 ? 'true' : null">
          <span v-for="b in brands" :key="b" class="marquee-item">{{ b }}</span>
        </div>
      </div>
    </section>

    <!-- ===================== STATS ===================== -->
    <section class="section pb-0">
      <div class="container-wide">
        <div class="stats-band reveal">
          <div class="row g-4 text-center">
            <div class="col-6 col-lg-3 stat">
              <div class="stat-num"><CountUp :value="stats ? stats.certified : 0" /></div>
              <div class="stat-lbl">parts certified</div>
            </div>
            <div class="col-6 col-lg-3 stat">
              <div class="stat-num grad-text"><CountUp :value="stats ? stats.avg_savings_pct : 0" suffix="%" /></div>
              <div class="stat-lbl">average savings vs. brand-new</div>
            </div>
            <div class="col-6 col-lg-3 stat">
              <div class="stat-num"><CountUp :value="stats ? stats.tests_run : 0" /></div>
              <div class="stat-lbl">stress tests logged</div>
            </div>
            <div class="col-6 col-lg-3 stat">
              <div class="stat-num"><CountUp :value="ceiling" :decimals="1" suffix="°C" /></div>
              <div class="stat-lbl">hottest a part can run and still pass</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== DEALS ===================== -->
    <section class="section" id="deals">
      <div class="container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 section-head reveal">
          <div>
            <div class="eyebrow"><i class="fa-solid fa-fire me-1"></i>In stock now</div>
            <h2>Today's deals</h2>
            <p>Every price below is for a tested, certified unit you can look up before you buy.</p>
            <p v-if="guest" class="small mt-n2 mb-0">
              <RouterLink :to="{ name: 'signin', query: { next: '/' } }" class="text-decoration-none"><i class="fa-solid fa-right-to-bracket me-1"></i>Sign in</RouterLink>
              or <RouterLink :to="{ name: 'register', query: { next: '/' } }" class="text-decoration-none">create an account</RouterLink> to add parts to your cart.
            </p>
          </div>
        </div>

        <div v-if="state === 'loading'" class="row g-4">
          <div class="col-sm-6 col-lg-4 col-xl-3 col-xxl" v-for="n in 5" :key="n"><div class="skeleton"></div></div>
        </div>
        <div v-else-if="state === 'error'" class="alert alert-warning">{{ error }}</div>
        <div v-else-if="!visible.length" class="empty-state">
          <i class="fa-solid fa-box-open"></i>
          <p class="mt-3 mb-0">Everything just sold out. New units are being tested — check back soon.</p>
        </div>

        <div v-else class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 row-cols-xxl-5 g-4 deals-grid">
          <div class="col" v-for="(p, i) in visible" :key="p.serial_hash">
            <div class="reveal h-100" :style="{ '--d': i % 5 }">
              <DealCard :item="p" :best="!!p.savings_pct && p.savings_pct === bestPct" :in-cart="inCart(p.serial_hash)"
                        :can-shop="canShop" @add="addToCart" />
            </div>
          </div>
        </div>

        <div v-if="state === 'ready' && items.length" class="text-center mt-5">
          <RouterLink :to="{ name: 'shop' }" class="btn btn-ghost px-4 py-2">
            Shop all {{ items.length }} certified part{{ items.length === 1 ? '' : 's' }}<i class="fa-solid fa-arrow-right ms-2"></i>
          </RouterLink>
          <p class="small text-muted-rc mt-2 mb-0">Filter by category, brand, price and test temperature.</p>
        </div>
      </div>

      <CartToast :message="cartNotice" />
    </section>

    <!-- ===================== RIGS ===================== -->
    <section class="section pt-2" id="rigs">
      <div class="container-wide">
        <RigShowcase />
      </div>
    </section>

    <!-- ===================== WHY RIGCRAFT ===================== -->
    <section class="section pt-2" id="why">
      <div class="container-wide">
        <div class="section-head text-center reveal">
          <div class="eyebrow">Why RigCraft</div>
          <h2>Used, without the gamble</h2>
          <p>Buying second-hand usually means trusting a stranger. We take the guesswork out.</p>
        </div>

        <div class="row g-4 align-items-stretch">
          <div class="col-lg-5 reveal">
            <div class="compare bad">
              <h3><i class="fa-solid fa-store-slash text-red"></i>Typical marketplace listing</h3>
              <ul>
                <li class="reveal" style="--d:1"><i class="fa-solid fa-xmark"></i><div><strong>"Working, no issues"</strong><span>Untested, or tested for five minutes at idle.</span></div></li>
                <li class="reveal" style="--d:2"><i class="fa-solid fa-xmark"></i><div><strong>Dusty, dried-out paste</strong><span>Years of grime that cooks the card under load.</span></div></li>
                <li class="reveal" style="--d:3"><i class="fa-solid fa-xmark"></i><div><strong>Screenshots you can't check</strong><span>Benchmarks that could be from any card.</span></div></li>
                <li class="reveal" style="--d:4"><i class="fa-solid fa-xmark"></i><div><strong>Meet-up, no returns</strong><span>If it dies next week, that's your problem.</span></div></li>
              </ul>
            </div>
          </div>

          <div class="col-lg-2 d-none d-lg-flex align-items-center justify-content-center reveal" style="--d:2">
            <div class="vs-badge">VS</div>
          </div>

          <div class="col-lg-5 reveal" style="--d:1">
            <div class="compare good">
              <h3><i class="fa-solid fa-microchip text-cyan"></i>RigCraft certified</h3>
              <ul>
                <li class="reveal" style="--d:2"><i class="fa-solid fa-check"></i><div><strong>30-minute full-load stress test</strong><span>Passes only at ≤ {{ ceiling.toFixed(1) }} °C with zero memory errors.</span></div></li>
                <li class="reveal" style="--d:3"><i class="fa-solid fa-check"></i><div><strong>Ultrasonic clean + fresh paste</strong><span>Runs cooler and quieter than the day it was sold used.</span></div></li>
                <li class="reveal" style="--d:4"><i class="fa-solid fa-check"></i><div><strong>QR label with its real test record</strong><span>Scan it and see who tested it, when and how hot it ran.</span></div></li>
                <li class="reveal" style="--d:5"><i class="fa-solid fa-check"></i><div><strong>{{ warranty }}-month warranty</strong><span>Covered from the day you buy it.</span></div></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== HOW IT WORKS ===================== -->
    <section class="section" id="how">
      <div class="container-wide">
        <div class="section-head text-center reveal">
          <div class="eyebrow">From their rig to yours</div>
          <h2>How every part earns its label</h2>
          <p>Four steps, signed off by a named technician, before anything reaches the shop.</p>
        </div>

        <div class="steps reveal">
          <div class="steps-line d-none d-lg-block"></div>
          <div class="row g-4">
            <div class="col-sm-6 col-lg-3"><div class="step2">
              <div class="step2-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
              <div class="step2-num">STEP 01</div><h3>We buy &amp; inspect</h3>
              <p>Every part is logged by its manufacturer serial and checked for physical damage.</p>
            </div></div>
            <div class="col-sm-6 col-lg-3"><div class="step2">
              <div class="step2-icon"><i class="fa-solid fa-spray-can-sparkles"></i></div>
              <div class="step2-num">STEP 02</div><h3>Deep clean &amp; re-paste</h3>
              <p>Ultrasonic PCB cleaning, then fresh thermal paste and pads where they're needed.</p>
            </div></div>
            <div class="col-sm-6 col-lg-3"><div class="step2">
              <div class="step2-icon"><i class="fa-solid fa-fire"></i></div>
              <div class="step2-num">STEP 03</div><h3>Stress test</h3>
              <p>30 minutes at full load in FurMark, Prime95 or MemTest86. Over {{ ceiling.toFixed(1) }} °C means rejected.</p>
            </div></div>
            <div class="col-sm-6 col-lg-3"><div class="step2">
              <div class="step2-icon"><i class="fa-solid fa-qrcode"></i></div>
              <div class="step2-num">STEP 04</div><h3>Certified &amp; listed</h3>
              <p>It gets a QR label, a public test record and a {{ warranty }}-month warranty, then goes on sale.</p>
            </div></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== FINAL CTA ===================== -->
    <section class="section pt-0">
      <div class="container-wide">
        <div class="cta-band reveal">
          <div class="eyebrow">Upgrade for less</div>
          <h2 class="mb-3">Your next upgrade is already tested.</h2>
          <p class="text-muted-rc mb-4 mx-auto" style="max-width: 36rem">
            Grab a certified part today, or scan the label on one you already own to see its full test record.
          </p>
          <div class="d-flex flex-wrap justify-content-center gap-3">
            <RouterLink :to="{ hash: '#deals' }" class="btn btn-rc btn-glow px-4 py-2"><i class="fa-solid fa-bolt me-2"></i>Shop deals</RouterLink>
            <RouterLink :to="{ name: 'verify' }" class="btn btn-ghost px-4 py-2"><i class="fa-solid fa-qrcode me-2"></i>Verify a part</RouterLink>
          </div>
        </div>
      </div>
    </section>
  </main>
</template>
