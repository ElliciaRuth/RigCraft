<script setup>
/**
 * Home page rigs: a row of complete builds you can swipe or step through with
 * the arrow buttons, and a full-size viewer (arrow keys, swipe or the buttons
 * step through the builds; Esc or a click outside closes it).
 * Builds and photos: lib/rigs.js.
 */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { RIGS, RIG_PART_ICONS, rigPhoto, rigPhotoSmall } from '@/lib/rigs.js';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const partIcon = (kind) => RIG_PART_ICONS[kind] || 'fa-puzzle-piece';
const coolIcon = (rig) => (rig.cooling.startsWith('Liquid') ? 'fa-droplet' : 'fa-fan');

// ---------------- Row ----------------
const rail = ref(null);
const atStart = ref(true);
const atEnd = ref(false);

function syncEnds() {
  const el = rail.value;
  if (!el) return;
  atStart.value = el.scrollLeft <= 4;
  atEnd.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
}

/** Scroll by as many whole cards as fit on screen. */
function step(dir) {
  const el = rail.value;
  const card = el?.querySelector('.rig-slot');
  if (!card) return;
  const pitch = card.offsetWidth + (parseFloat(getComputedStyle(el).columnGap) || 0);
  const perView = Math.max(1, Math.floor((el.clientWidth + 1) / pitch));
  el.scrollBy({ left: dir * pitch * perView, behavior: reduceMotion ? 'auto' : 'smooth' });
}

onMounted(() => {
  syncEnds();
  window.addEventListener('resize', syncEnds);
});

// ---------------- Full-size viewer ----------------
const index = ref(-1);
const current = computed(() => RIGS[index.value] || null);
const closeBtn = ref(null);
let opener = null;

function onKey(e) {
  if (e.key === 'Escape') close();
  else if (e.key === 'ArrowLeft') go(-1);
  else if (e.key === 'ArrowRight') go(1);
}

async function openAt(i, e) {
  opener = e?.currentTarget || null;
  index.value = i;
  document.body.style.overflow = 'hidden';
  document.addEventListener('keydown', onKey);
  await nextTick();
  closeBtn.value?.focus();
}

function close() {
  if (index.value < 0) return;
  index.value = -1;
  document.body.style.overflow = '';
  document.removeEventListener('keydown', onKey);
  opener?.focus();
  opener = null;
}

const go = (dir) => { index.value = (index.value + dir + RIGS.length) % RIGS.length; };

// Swipe left / right on phones
let touchX = null;
const touchStart = (e) => { touchX = e.changedTouches[0].clientX; };
function touchEnd(e) {
  if (touchX === null) return;
  const dx = e.changedTouches[0].clientX - touchX;
  touchX = null;
  if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
}

onBeforeUnmount(() => {
  window.removeEventListener('resize', syncEnds);
  document.removeEventListener('keydown', onKey);
  document.body.style.overflow = '';
});
</script>

<template>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 section-head reveal">
    <div>
      <div class="eyebrow"><i class="fa-solid fa-computer me-1"></i>Complete builds</div>
      <h2>Rigs, not just parts</h2>
      <p>Some of the PCs we've put together. Tap a build to see it full size, then shop the parts to make your own.</p>
    </div>
    <div class="rig-arrows" role="group" aria-label="Scroll the builds">
      <button type="button" class="rig-arrow" :disabled="atStart" aria-label="Previous builds" @click="step(-1)">
        <i class="fa-solid fa-chevron-left"></i>
      </button>
      <button type="button" class="rig-arrow" :disabled="atEnd" aria-label="More builds" @click="step(1)">
        <i class="fa-solid fa-chevron-right"></i>
      </button>
    </div>
  </div>

  <ul ref="rail" class="rig-rail reveal" style="--d:1" @scroll.passive="syncEnds">
    <li v-for="(r, i) in RIGS" :key="r.slug" class="rig-slot">
      <article class="rig" :style="{ '--glow': r.glow }">
        <button type="button" class="rig-media" :aria-label="'View ' + r.name + ' full size'" @click="openAt(i, $event)">
          <img :src="rigPhotoSmall(r)" :alt="r.name + ' PC build'" loading="lazy" decoding="async"
               :style="r.focus ? { objectPosition: r.focus } : null">
          <span class="rig-cool"><i class="fa-solid" :class="coolIcon(r)"></i>{{ r.cooling }}</span>
          <span class="rig-zoom" aria-hidden="true"><i class="fa-solid fa-expand"></i></span>
        </button>
        <div class="rig-body">
          <h3 class="rig-name">{{ r.name }}</h3>
          <p class="rig-blurb">{{ r.blurb }}</p>
          <ul class="rig-parts">
            <li v-for="[kind, text] in r.parts" :key="text"><i class="fa-solid" :class="partIcon(kind)"></i>{{ text }}</li>
          </ul>
        </div>
      </article>
    </li>
  </ul>

  <div class="text-center mt-4">
    <RouterLink :to="{ name: 'shop' }" class="btn btn-ghost px-4 py-2">
      Shop parts for your build<i class="fa-solid fa-arrow-right ms-2"></i>
    </RouterLink>
  </div>

  <Teleport to="body">
    <div v-if="current" class="rig-viewer" role="dialog" aria-modal="true" :aria-label="current.name"
         @click.self="close" @touchstart.passive="touchStart" @touchend="touchEnd">
      <button ref="closeBtn" type="button" class="pz-close" aria-label="Close" @click="close"><i class="fa-solid fa-xmark"></i></button>
      <button type="button" class="rig-nav prev" aria-label="Previous build" @click="go(-1)"><i class="fa-solid fa-chevron-left"></i></button>

      <figure class="rig-figure" :style="{ '--glow': current.glow }">
        <img :key="current.slug" :src="rigPhoto(current)" :alt="current.name + ' PC build'">
        <figcaption>
          <div class="rig-count">{{ index + 1 }} / {{ RIGS.length }}</div>
          <h3 class="rig-name">{{ current.name }}</h3>
          <p>{{ current.blurb }}</p>
          <div class="rig-cool static"><i class="fa-solid" :class="coolIcon(current)"></i>{{ current.cooling }}</div>
          <ul class="rig-parts">
            <li v-for="[kind, text] in current.parts" :key="text"><i class="fa-solid" :class="partIcon(kind)"></i>{{ text }}</li>
          </ul>
        </figcaption>
      </figure>

      <button type="button" class="rig-nav next" aria-label="Next build" @click="go(1)"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </Teleport>
</template>
