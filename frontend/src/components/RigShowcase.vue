<script setup>
/**
 * Home page rigs: a row of complete builds you can swipe or step through with
 * the arrow buttons, and a full-size viewer (arrow keys, swipe or the buttons
 * step through the builds; Esc or a click outside closes it) with zoom: click or
 * double-tap, wheel or pinch, drag to pan, + − 0 keys and the zoom bar.
 * Builds and photos: lib/rigs.js.
 */
import { ref, reactive, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
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

const PAN_KEYS = { ArrowLeft: [60, 0], ArrowRight: [-60, 0], ArrowUp: [0, 60], ArrowDown: [0, -60] };
function onKey(e) {
  if (e.key === 'Escape') close();
  else if (e.key === '+' || e.key === '=') zoomStep(1);
  else if (e.key === '-' || e.key === '_') zoomStep(-1);
  else if (e.key === '0') resetZoom();
  else if (zoomed.value && PAN_KEYS[e.key]) {   // zoomed in: arrows move around the photo
    e.preventDefault();
    zoom.x += PAN_KEYS[e.key][0]; zoom.y += PAN_KEYS[e.key][1]; clampPan();
  }
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

// ---------------- Zoom (full-size viewer) ----------------
// Click (mouse) or double-tap (touch) zooms in on that spot; again goes back to
// fit. The wheel or a pinch zooms in steps, dragging moves around while zoomed,
// and at fit size a sideways swipe or drag changes build. Keys: + − 0, arrows.
const MAX_ZOOM = 3;   // the photos are about 1000 px, so more would just blur
const stage = ref(null);
const zoom = reactive({ s: 1, x: 0, y: 0 });   // scale, and pan in screen px
const zoomed = computed(() => zoom.s > 1.001);
const dragging = ref(false);
const zoomHint = window.matchMedia('(hover: hover) and (pointer: fine)').matches
  ? 'Click the photo to zoom in, scroll to zoom in steps, drag to look around.'
  : 'Double-tap or pinch the photo to zoom in, drag to look around.';

function resetZoom() { zoom.s = 1; zoom.x = 0; zoom.y = 0; }
watch(index, resetZoom);

/** Keep the photo covering its frame (no empty edges while zoomed). */
function clampPan() {
  const r = stage.value?.getBoundingClientRect();
  if (!r) return;
  const mx = (r.width * (zoom.s - 1)) / 2, my = (r.height * (zoom.s - 1)) / 2;
  zoom.x = Math.min(mx, Math.max(-mx, zoom.x));
  zoom.y = Math.min(my, Math.max(-my, zoom.y));
}
/** A screen point, relative to the centre of the photo's frame. */
function fromCentre(clientX, clientY) {
  const r = stage.value.getBoundingClientRect();
  return { x: clientX - r.left - r.width / 2, y: clientY - r.top - r.height / 2 };
}
/** Zoom to scale s, keeping point c (relative to the centre) in place on screen. */
function zoomTo(s, c = { x: 0, y: 0 }) {
  s = Math.min(MAX_ZOOM, Math.max(1, s));
  const k = s / zoom.s;
  zoom.x = c.x - k * (c.x - zoom.x);
  zoom.y = c.y - k * (c.y - zoom.y);
  zoom.s = s;
  if (zoomed.value) clampPan(); else resetZoom();
}
const zoomStep = (dir) => zoomTo(zoom.s * (dir > 0 ? 1.5 : 1 / 1.5));
const toggleAt = (c) => (zoomed.value ? resetZoom() : zoomTo(2.5, c));
const onWheel = (e) => zoomTo(zoom.s * (e.deltaY < 0 ? 1.25 : 1 / 1.25), fromCentre(e.clientX, e.clientY));

const pointers = new Map();   // pointerId → { x, y }
let gesture = null;           // { kind: 'pan', x0, y0, tx, ty, moved } | { kind: 'pinch', dist, s }
let lastTap = 0;

function onPointerDown(e) {
  if (e.button > 0) return;
  stage.value.setPointerCapture(e.pointerId);
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
  if (pointers.size === 2) {
    const [a, b] = [...pointers.values()];
    gesture = { kind: 'pinch', dist: Math.hypot(a.x - b.x, a.y - b.y) || 1, s: zoom.s };
  } else if (pointers.size === 1) {
    gesture = { kind: 'pan', x0: e.clientX, y0: e.clientY, tx: zoom.x, ty: zoom.y, moved: false };
  }
  dragging.value = true;
}

function onPointerMove(e) {
  if (!gesture || !pointers.has(e.pointerId)) return;
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
  if (gesture.kind === 'pinch' && pointers.size >= 2) {
    const [a, b] = [...pointers.values()];
    zoomTo(gesture.s * (Math.hypot(a.x - b.x, a.y - b.y) / gesture.dist), fromCentre((a.x + b.x) / 2, (a.y + b.y) / 2));
  } else if (gesture.kind === 'pan') {
    const dx = e.clientX - gesture.x0, dy = e.clientY - gesture.y0;
    if (Math.abs(dx) + Math.abs(dy) > 6) gesture.moved = true;
    if (zoomed.value) { zoom.x = gesture.tx + dx; zoom.y = gesture.ty + dy; clampPan(); }
  }
}

function onPointerUp(e) {
  if (!pointers.has(e.pointerId)) return;
  pointers.delete(e.pointerId);
  if (pointers.size) {   // one finger of a pinch lifted: carry on panning with the other
    const [p] = [...pointers.values()];
    gesture = { kind: 'pan', x0: p.x, y0: p.y, tx: zoom.x, ty: zoom.y, moved: true };
    return;
  }
  const g = gesture;
  gesture = null;
  dragging.value = false;
  if (!g || g.kind !== 'pan' || e.type === 'pointercancel') return;

  if (!g.moved) {
    const c = fromCentre(e.clientX, e.clientY);
    if (e.pointerType === 'mouse') toggleAt(c);
    else if (Date.now() - lastTap < 320) { toggleAt(c); lastTap = 0; }
    else lastTap = Date.now();
    return;
  }
  const dx = e.clientX - g.x0, dy = e.clientY - g.y0;
  if (!zoomed.value && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1);
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
         @click.self="close">
      <button ref="closeBtn" type="button" class="pz-close" aria-label="Close" @click="close"><i class="fa-solid fa-xmark"></i></button>
      <button type="button" class="rig-nav prev" aria-label="Previous build" @click="go(-1)"><i class="fa-solid fa-chevron-left"></i></button>

      <figure class="rig-figure" :style="{ '--glow': current.glow }">
        <div class="rig-stage-wrap">
          <div ref="stage" class="rig-stage" :class="{ zoomed, dragging }"
               @wheel.prevent="onWheel" @pointerdown="onPointerDown" @pointermove="onPointerMove"
               @pointerup="onPointerUp" @pointercancel="onPointerUp">
            <img :key="current.slug" :src="rigPhoto(current)" :alt="current.name + ' PC build'" draggable="false"
                 :style="{ transform: `translate(${zoom.x}px, ${zoom.y}px) scale(${zoom.s})` }">
          </div>
          <div class="rig-zoombar" role="group" aria-label="Zoom">
            <button type="button" aria-label="Zoom out" :disabled="!zoomed" @click="zoomStep(-1)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <output aria-live="polite">{{ Math.round(zoom.s * 100) }}%</output>
            <button type="button" aria-label="Zoom in" :disabled="zoom.s >= MAX_ZOOM" @click="zoomStep(1)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" aria-label="Fit to screen" :disabled="!zoomed" @click="resetZoom"><i class="fa-solid fa-compress"></i></button>
          </div>
        </div>
        <figcaption>
          <div class="rig-count">{{ index + 1 }} / {{ RIGS.length }}</div>
          <h3 class="rig-name">{{ current.name }}</h3>
          <p>{{ current.blurb }}</p>
          <div class="rig-cool static"><i class="fa-solid" :class="coolIcon(current)"></i>{{ current.cooling }}</div>
          <ul class="rig-parts">
            <li v-for="[kind, text] in current.parts" :key="text"><i class="fa-solid" :class="partIcon(kind)"></i>{{ text }}</li>
          </ul>
          <p class="rig-zoom-hint"><i class="fa-solid fa-magnifying-glass me-1"></i>{{ zoomHint }}</p>
        </figcaption>
      </figure>

      <button type="button" class="rig-nav next" aria-label="Next build" @click="go(1)"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
  </Teleport>
</template>
