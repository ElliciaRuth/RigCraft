<script setup>
/**
 * Product photo with roll-over zoom (mouse) and click/tap to view full size.
 * The zoom pane is absolutely positioned against the nearest positioned
 * ancestor (.unit-top on the verify page), so it covers the text column.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
  src:  { type: String, required: true },
  alt:  { type: String, default: '' },
  zoom: { type: Number, default: 2.5 },
});
const emit = defineEmits(['error']);

const box  = ref(null);
const img  = ref(null);
const pane = ref(null);

const canHover = ref(false);
const active   = ref(false);
const open     = ref(false);
const lens     = ref({ left: 0, top: 0, width: 0, height: 0 });
const bg       = ref({ size: '0 0', pos: '0 0' });

let mq;
const syncHover = () => { canHover.value = !!mq?.matches; };
onMounted(() => {
  mq = window.matchMedia('(hover: hover) and (pointer: fine) and (min-width: 576px)');
  syncHover();
  mq.addEventListener?.('change', syncHover);
});
onBeforeUnmount(() => {
  mq?.removeEventListener?.('change', syncHover);
  document.removeEventListener('keydown', onKey);
  document.body.style.overflow = '';
});
watch(() => props.src, () => { active.value = false; open.value = false; });

/** Where the picture actually sits inside the <img> box (object-fit: contain + padding). */
function imageRect() {
  const el = img.value;
  const r  = el.getBoundingClientRect();
  const cs = getComputedStyle(el);
  const pl = parseFloat(cs.paddingLeft), pr = parseFloat(cs.paddingRight);
  const pt = parseFloat(cs.paddingTop),  pb = parseFloat(cs.paddingBottom);
  const cw = r.width - pl - pr, ch = r.height - pt - pb;
  const nw = el.naturalWidth || cw, nh = el.naturalHeight || ch;
  const s  = Math.min(cw / nw, ch / nh);
  const w  = nw * s, h = nh * s;
  return { x: r.left + pl + (cw - w) / 2, y: r.top + pt + (ch - h) / 2, w, h, boxX: r.left, boxY: r.top };
}

function move(e) {
  if (!canHover.value || !img.value || !pane.value) return;
  const ir = imageRect();
  const pr = pane.value.getBoundingClientRect();
  if (!pr.width || !ir.w) return;
  const z  = props.zoom;
  // Lens = the part of the picture the pane shows, kept inside the picture.
  const lw = Math.min(pr.width / z, ir.w);
  const lh = Math.min(pr.height / z, ir.h);
  const cx = Math.min(Math.max(e.clientX - ir.x, lw / 2), ir.w - lw / 2);
  const cy = Math.min(Math.max(e.clientY - ir.y, lh / 2), ir.h - lh / 2);
  lens.value = { left: ir.x - ir.boxX + cx - lw / 2, top: ir.y - ir.boxY + cy - lh / 2, width: lw, height: lh };
  bg.value = {
    size: `${ir.w * z}px ${ir.h * z}px`,
    pos:  `${-(cx - lw / 2) * z + (pr.width - lw * z) / 2}px ${-(cy - lh / 2) * z + (pr.height - lh * z) / 2}px`,
  };
}
function enter(e) { if (canHover.value) { active.value = true; requestAnimationFrame(() => move(e)); } }
function leave()  { active.value = false; }

function onKey(e) { if (e.key === 'Escape') close(); }
function show() {
  open.value = true; active.value = false;
  document.body.style.overflow = 'hidden';
  document.addEventListener('keydown', onKey);
}
function close() {
  open.value = false;
  document.body.style.overflow = '';
  document.removeEventListener('keydown', onKey);
}

const hint = computed(() => (canHover.value ? 'Roll over image to zoom in · click to enlarge' : 'Tap image to enlarge'));
</script>

<template>
  <div class="pz">
    <button ref="box" type="button" class="unit-photo pz-box" :class="{ zooming: active }"
            :aria-label="'Enlarge photo: ' + alt"
            @mouseenter="enter" @mousemove="move" @mouseleave="leave" @click="show">
      <img ref="img" :src="src" :alt="alt" @error="emit('error')">
      <span v-show="active" class="pz-lens" aria-hidden="true"
            :style="{ left: lens.left + 'px', top: lens.top + 'px', width: lens.width + 'px', height: lens.height + 'px' }"></span>
    </button>
    <div class="pz-hint"><i class="fa-solid fa-magnifying-glass me-1"></i>{{ hint }}</div>

    <div v-show="active" ref="pane" class="pz-pane" aria-hidden="true"
         :style="{ backgroundImage: `url(&quot;${src}&quot;)`, backgroundSize: bg.size, backgroundPosition: bg.pos }"></div>

    <Teleport to="body">
      <div v-if="open" class="pz-lightbox" role="dialog" aria-modal="true" :aria-label="alt" @click="close">
        <button type="button" class="pz-close" aria-label="Close" @click.stop="close"><i class="fa-solid fa-xmark"></i></button>
        <img :src="src" :alt="alt" @click.stop>
      </div>
    </Teleport>
  </div>
</template>
