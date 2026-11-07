<script setup>
/** Number that counts up from 0 the first time it scrolls into view. */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  value:    { type: Number, default: 0 },
  decimals: { type: Number, default: 0 },
  suffix:   { type: String, default: '' },
  duration: { type: Number, default: 1600 },
});

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const el    = ref(null);
const shown = ref(0);
let visible = false, io = null, frame = 0;

const text = computed(() =>
  shown.value.toLocaleString('en-PH', { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals }) + props.suffix);

function run() {
  cancelAnimationFrame(frame);
  if (reduceMotion) { shown.value = props.value; return; }
  const to = props.value, t0 = performance.now();
  const tick = (t) => {
    const p = Math.min(1, (t - t0) / props.duration);
    shown.value = to * (1 - Math.pow(1 - p, 3));   // ease-out cubic
    if (p < 1) frame = requestAnimationFrame(tick);
  };
  frame = requestAnimationFrame(tick);
}

onMounted(() => {
  io = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting && !visible) { visible = true; run(); io.disconnect(); }
  }, { threshold: 0.4 });
  io.observe(el.value);
});
onBeforeUnmount(() => { io?.disconnect(); cancelAnimationFrame(frame); });
watch(() => props.value, () => { if (visible) run(); });   // data arrived after it became visible
</script>

<template>
  <span ref="el" class="tabular">{{ text }}</span>
</template>
