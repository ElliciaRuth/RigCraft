<script setup>
/**
 * OCCT results for one rig, shown in the rig viewer (RigShowcase.vue): a tab
 * per test (CPU, 3D Adaptive, Power), the result tiles, and a chart of one
 * reading over the 30-minute run. The chart replays the run like a time-lapse
 * (8 s) when a test opens, hover or tap shows the reading at that minute, and
 * "Show as table" lists every 5 minutes. Sample data: lib/occt.js.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { benchFor, clockTime, RUN_MINUTES } from '@/lib/occt.js';

const props = defineProps({ rig: { type: Object, required: true } });

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const bench = computed(() => benchFor(props.rig));
const testIdx = ref(0);
const metricIdx = ref(0);
const test = computed(() => bench.value.tests[testIdx.value]);
const metric = computed(() => test.value.metrics[metricIdx.value]);
const fmt = (m, v) => `${v.toFixed(m.dec)} ${m.unit}`;
const statLabel = (m) => (m.stat === 'avg' ? 'Average ' : m.unit === 'W' ? 'Peak ' : 'Max ') + m.label;

// ---------------- Chart geometry ----------------
const box = ref(null);
const width = ref(560);
const H = 230;
const PAD = { l: 46, r: 16, t: 26, b: 30 };
let ro = null;
onMounted(() => {
  ro = new ResizeObserver(([e]) => { width.value = Math.max(260, Math.round(e.contentRect.width)); });
  ro.observe(box.value);
});

/** Round axis bounds to a tidy step, about four gridlines. */
const yAxis = computed(() => {
  const vals = metric.value.values;
  const lo = Math.min(...vals), hi = Math.max(...vals);
  const steps = [0.1, 0.2, 0.25, 0.5, 1, 2, 2.5, 5, 10, 20, 25, 50, 100, 200, 250, 500];
  const step = steps.find((s) => (Math.ceil(hi / s) - Math.floor(lo / s)) <= 5) || 1000;
  const min = Math.floor(lo / step) * step, max = Math.ceil(hi / step) * step || step;
  const ticks = [];
  for (let v = min; v <= max + step / 2; v += step) ticks.push(Number(v.toFixed(2)));
  return { min, max: max === min ? min + step : max, ticks, dec: step < 1 ? (step < 0.25 ? 1 : 2) : 0 };
});
const x = (min) => PAD.l + (min / RUN_MINUTES) * (width.value - PAD.l - PAD.r);
const y = (v) => PAD.t + (1 - (v - yAxis.value.min) / (yAxis.value.max - yAxis.value.min)) * (H - PAD.t - PAD.b);
const xTicks = [0, 5, 10, 15, 20, 25, 30];

// ---------------- Replay (time-lapse of the run) ----------------
const REPLAY_MS = 8000;
const shown = ref(bench.value.minutes.length);   // how many readings are drawn
const playing = ref(false);
let raf = 0;

function stop() { cancelAnimationFrame(raf); playing.value = false; }
function play() {
  stop();
  const n = bench.value.minutes.length;
  if (reduceMotion) { shown.value = n; return; }
  playing.value = true;
  const start = performance.now();
  const frame = (now) => {
    const k = Math.min(1, (now - start) / REPLAY_MS);
    shown.value = Math.max(1, Math.round(k * (n - 1)) + 1);
    if (k < 1) raf = requestAnimationFrame(frame); else playing.value = false;
  };
  raf = requestAnimationFrame(frame);
}
const finish = () => { stop(); shown.value = bench.value.minutes.length; };

watch(testIdx, () => { metricIdx.value = 0; play(); });
watch(() => props.rig.slug, () => play());
onMounted(play);
onBeforeUnmount(() => { stop(); ro?.disconnect(); });

const path = computed(() => {
  const mins = bench.value.minutes, vals = metric.value.values;
  let d = '';
  for (let i = 0; i < shown.value; i++) d += `${i ? 'L' : 'M'}${x(mins[i]).toFixed(1)},${y(vals[i]).toFixed(1)}`;
  return d;
});
const area = computed(() => {
  const last = bench.value.minutes[shown.value - 1];
  return `${path.value}L${x(last).toFixed(1)},${H - PAD.b}L${PAD.l},${H - PAD.b}Z`;
});
const head = computed(() => shown.value - 1);

// ---------------- Hover / tap readout ----------------
const hover = ref(-1);
const point = computed(() => (hover.value >= 0 ? hover.value : playing.value ? head.value : -1));
function onMove(e) {
  const r = e.currentTarget.getBoundingClientRect();
  const min = ((e.clientX - r.left - PAD.l) / (width.value - PAD.l - PAD.r)) * RUN_MINUTES;
  const i = Math.round((min / RUN_MINUTES) * (bench.value.minutes.length - 1));
  hover.value = Math.min(head.value, Math.max(0, i));
}
const tip = computed(() => {
  const i = point.value;
  if (i < 0) return null;
  const px = x(bench.value.minutes[i]);
  return {
    left: Math.min(width.value - 70, Math.max(70, px)), top: y(metric.value.values[i]), px,
    time: clockTime(bench.value.minutes[i]), value: fmt(metric.value, metric.value.values[i]),
  };
});

// ---------------- Table view (every 5 minutes) ----------------
const tableRows = computed(() => bench.value.minutes
  .map((min, i) => ({ min, i }))
  .filter(({ min }) => min % 5 === 0)
  .map(({ min, i }) => ({ time: clockTime(min), cells: test.value.metrics.map((m) => fmt(m, m.values[i])) })));
</script>

<template>
  <section class="rig-bench" :aria-label="'OCCT results for ' + rig.name">
    <header class="rb-head">
      <h4><i class="fa-solid fa-gauge-high me-2"></i>OCCT stability tests<span class="rb-for">{{ rig.name }}</span></h4>
      <span class="rb-sample" title="Sample figures for the demo, not measured on this PC">Sample data</span>
    </header>

    <div class="rb-tabs" role="tablist" aria-label="Test">
      <button v-for="(t, i) in bench.tests" :key="t.id" type="button" role="tab" :aria-selected="i === testIdx"
              :class="{ on: i === testIdx }" @click="testIdx = i">
        <i class="fa-solid" :class="t.icon"></i>{{ t.name }}
      </button>
    </div>
    <p class="rb-about">{{ test.about }} · {{ RUN_MINUTES }} min</p>

    <ul class="rb-tiles">
      <li class="rb-pass">
        <span class="rb-k">Result</span>
        <span class="rb-v"><i class="fa-solid fa-circle-check"></i>Passed</span>
        <span class="rb-s">{{ test.errors }} errors</span>
      </li>
      <li v-for="m in test.metrics" :key="m.id">
        <span class="rb-k">{{ statLabel(m) }}</span>
        <span class="rb-v">{{ m.summary.toFixed(m.dec) }}<small>{{ m.unit }}</small></span>
      </li>
    </ul>

    <div class="rb-chart-head">
      <div class="rb-metrics" role="group" aria-label="Reading">
        <button v-for="(m, i) in test.metrics" :key="m.id" type="button" :aria-pressed="i === metricIdx"
                :class="{ on: i === metricIdx }" @click="metricIdx = i">{{ m.label }}</button>
      </div>
      <div class="rb-play">
        <output aria-live="off">{{ clockTime(bench.minutes[head]) }} / {{ RUN_MINUTES }}:00</output>
        <button v-if="playing" type="button" @click="finish"><i class="fa-solid fa-forward-step me-1"></i>Skip</button>
        <button v-else type="button" @click="play"><i class="fa-solid fa-rotate-right me-1"></i>Replay</button>
      </div>
    </div>

    <div ref="box" class="rb-chart">
      <svg :width="width" :height="H" role="img"
           :aria-label="`${metric.label} over the ${RUN_MINUTES}-minute ${test.name} test`"
           @pointermove="onMove" @pointerdown="onMove" @pointerleave="hover = -1">
        <defs>
          <linearGradient :id="'rb-fill-' + rig.slug" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="var(--glow)" stop-opacity=".22" />
            <stop offset="1" stop-color="var(--glow)" stop-opacity="0" />
          </linearGradient>
        </defs>
        <g class="rb-grid">
          <g v-for="t in yAxis.ticks" :key="'y' + t">
            <line :x1="PAD.l" :x2="width - PAD.r" :y1="y(t)" :y2="y(t)" />
            <text :x="PAD.l - 8" :y="y(t)" dy=".32em" text-anchor="end">{{ t.toFixed(yAxis.dec) }}</text>
          </g>
          <text v-for="t in xTicks" :key="'x' + t" :x="x(t)" :y="H - 10" text-anchor="middle">{{ t === 30 ? '30 min' : t }}</text>
          <text :x="PAD.l - 8" :y="10" text-anchor="end" class="rb-unit">{{ metric.unit }}</text>
        </g>
        <path :d="area" :fill="`url(#rb-fill-${rig.slug})`" />
        <path :d="path" class="rb-line" />
        <g v-if="tip">
          <line class="rb-cross" :x1="tip.px" :x2="tip.px" :y1="PAD.t" :y2="H - PAD.b" />
          <circle class="rb-dot" :cx="tip.px" :cy="tip.top" r="5" />
        </g>
        <rect :x="PAD.l" :y="0" :width="width - PAD.l - PAD.r" :height="H" fill="transparent" />
      </svg>
      <div v-if="tip" class="rb-tip" :style="{ left: tip.left + 'px', top: tip.top + 'px' }">
        <span>{{ tip.time }}</span><strong>{{ tip.value }}</strong>
      </div>
    </div>

    <details class="rb-table">
      <summary>Show as table</summary>
      <div class="table-responsive">
        <table>
          <thead><tr><th scope="col">Time</th><th v-for="m in test.metrics" :key="m.id" scope="col">{{ m.label }}</th></tr></thead>
          <tbody><tr v-for="row in tableRows" :key="row.time"><th scope="row">{{ row.time }}</th><td v-for="(c, j) in row.cells" :key="j">{{ c }}</td></tr></tbody>
        </table>
      </div>
    </details>
  </section>
</template>
