<script setup>
/** Product card for the home page deals and the shop. */
import { ref, computed } from 'vue';
import { pesoWhole, categoryIcon } from '@/lib/format.js';
import { productPhoto } from '@/lib/photos.js';

const props = defineProps({
  item:    { type: Object, required: true },   // a row from /api/shop
  best:    { type: Boolean, default: false },  // show the "Best deal" ribbon
  inCart:  { type: Boolean, default: false },
  canShop: { type: Boolean, default: false },
});
defineEmits(['add']);

// Models without a photo, or whose photo fails to load, show the category icon.
const broken = ref(false);
const photo  = computed(() => (broken.value ? null : productPhoto(props.item.model_name)));
const report = computed(() => ({ name: 'verify', query: { hash: props.item.serial_hash } }));

// Card glow follows the cursor
function glow(e) {
  const card = e.currentTarget, r = card.getBoundingClientRect();
  card.style.setProperty('--mx', `${e.clientX - r.left}px`);
  card.style.setProperty('--my', `${e.clientY - r.top}px`);
}
</script>

<template>
  <article class="deal" @mousemove="glow">
    <div class="deal-media" :class="{ 'has-photo': photo }">
      <span class="deal-cert"><i class="fa-solid fa-circle-check me-1"></i>Certified</span>
      <span v-if="item.savings_pct" class="deal-off">−{{ item.savings_pct }}%</span>
      <img v-if="photo" class="deal-photo" :src="photo" :alt="item.brand + ' ' + item.model_name" loading="lazy" decoding="async" @error="broken = true">
      <i v-else class="fa-solid deal-icon" :class="categoryIcon(item.category)"></i>
      <span v-if="best" class="deal-best"><i class="fa-solid fa-crown me-1"></i>Best deal</span>
    </div>
    <div class="deal-body">
      <div class="deal-meta">{{ item.brand }} · {{ item.category }}</div>
      <h3 class="deal-name">{{ item.model_name }}</h3>
      <div class="deal-facts">
        <span class="fact ok"><i class="fa-solid fa-temperature-half me-1"></i>{{ item.peak_temp_celsius.toFixed(1) }} °C peak</span>
        <span class="fact"><i class="fa-solid fa-shield-halved me-1"></i>{{ item.warranty_months }}-mo warranty</span>
      </div>
      <div class="mt-auto">
        <div class="d-flex align-items-end justify-content-between gap-2">
          <div>
            <div v-if="item.savings" class="deal-was">{{ pesoWhole(item.retail_price) }} new</div>
            <div class="deal-price">{{ pesoWhole(item.listing_price) }}</div>
          </div>
          <div v-if="item.savings" class="deal-save">Save {{ pesoWhole(item.savings) }}</div>
        </div>
        <div v-if="canShop" class="deal-actions">
          <RouterLink v-if="inCart" class="btn btn-in-cart" :to="{ name: 'cart' }"><i class="fa-solid fa-check me-1"></i>In cart</RouterLink>
          <button v-else type="button" class="btn btn-rc" @click="$emit('add', item)"><i class="fa-solid fa-cart-plus me-1"></i>Add to cart</button>
          <RouterLink class="btn btn-deal" :to="report" :title="'Test report for ' + item.model_name">Report <i class="fa-solid fa-arrow-right ms-1"></i></RouterLink>
        </div>
        <RouterLink v-else class="btn btn-deal w-100" :to="report">View test report <i class="fa-solid fa-arrow-right ms-1"></i></RouterLink>
      </div>
    </div>
  </article>
</template>
