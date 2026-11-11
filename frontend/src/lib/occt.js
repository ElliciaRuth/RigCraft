/**
 * OCCT stability results for the home page rigs (components/RigBench.vue).
 *
 * SAMPLE DATA for the demo: nothing here was measured. Each rig in lib/rigs.js
 * has `bench: { cpu: [maxTemp °C, watts, GHz], gpu: [maxTemp °C, watts, MHz] }`
 * and this file turns that into 30-minute runs (one reading every 30 s) for
 * OCCT's CPU, 3D Adaptive and Power tests. The curves are seeded by the rig's
 * slug, so a rig always shows the same run. To show real results, replace
 * benchFor() with readings exported from OCCT (same shape).
 */

export const RUN_MINUTES = 30;
const STEP_SECONDS = 30;
const POINTS = (RUN_MINUTES * 60) / STEP_SECONDS + 1;   // 61, including 0:00

/** Small seeded random number generator (mulberry32), so every rig keeps the same run. */
function seeded(text) {
  let h = 1779033703;
  for (const ch of text) h = Math.imul(h ^ ch.charCodeAt(0), 3432918353), h = (h << 13) | (h >>> 19);
  return () => {
    h = (h + 0x6D2B79F5) | 0;
    let t = Math.imul(h ^ (h >>> 15), 1 | h);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const minutes = Array.from({ length: POINTS }, (_, i) => (i * STEP_SECONDS) / 60);
const round = (v, dec) => Number(v.toFixed(dec));

/** Temperature: idles, then climbs towards its peak (tau = minutes to get most of the way). */
function tempRun(rand, idle, peak, tau) {
  return minutes.map((t) => Math.min(peak, idle + (peak - idle - 1.2) * (1 - Math.exp(-t / tau)) + (rand() - 0.5) * 1.8));
}
/** Clock: boosts high at the start, then settles a little as the part warms up. */
function clockRun(rand, clock) {
  return minutes.map((t, i) => (i === 0 ? clock * 0.86
    : clock * (1 + 0.025 * Math.exp(-t / 2) - 0.012 * (1 - Math.exp(-t / 6)) + (rand() - 0.5) * 0.008)));
}
/** Power: jumps to full load straight away, a little higher while the clock is boosting. */
function powerRun(rand, idle, watts) {
  return minutes.map((t, i) => (i === 0 ? idle : watts * (1 + 0.04 * Math.exp(-t / 2) + (rand() - 0.5) * 0.04)));
}

const metric = (id, label, unit, dec, values, stat) => ({ id, label, unit, dec, stat, values: values.map((v) => round(v, dec)) });

const cache = new Map();

/** The three OCCT tests for one rig: [{ id, name, about, errors, metrics: [...] }]. */
export function benchFor(rig) {
  if (cache.has(rig.slug)) return cache.get(rig.slug);
  const [cpuPeak, cpuW, cpuGHz] = rig.bench.cpu;
  const [gpuPeak, gpuW, gpuMHz] = rig.bench.gpu;
  const liquid = rig.cooling.startsWith('Liquid');
  const r = (name) => seeded(`${rig.slug}:${name}`);
  const cpuTau = liquid ? 4 : 2;   // water takes longer to warm up than a heatsink

  const tests = [
    {
      id: 'cpu', name: 'CPU', icon: 'fa-microchip', about: 'All CPU cores at full load',
      metrics: [
        metric('cpu-temp', 'CPU temperature', '°C', 0, tempRun(r('cpu-t'), 38, cpuPeak, cpuTau), 'max'),
        metric('cpu-clock', 'CPU clock', 'GHz', 2, clockRun(r('cpu-c'), cpuGHz), 'avg'),
        metric('cpu-power', 'CPU power', 'W', 0, powerRun(r('cpu-p'), 18, cpuW), 'max'),
      ],
    },
    {
      id: 'gpu', name: '3D Adaptive', icon: 'fa-display', about: 'Graphics card under a changing 3D load',
      metrics: [
        metric('gpu-temp', 'GPU temperature', '°C', 0, tempRun(r('gpu-t'), 40, gpuPeak, 3), 'max'),
        metric('gpu-clock', 'GPU clock', 'MHz', 0, clockRun(r('gpu-c'), gpuMHz), 'avg'),
        metric('gpu-power', 'GPU power', 'W', 0, powerRun(r('gpu-p'), 24, gpuW), 'max'),
      ],
    },
    {
      id: 'power', name: 'Power', icon: 'fa-bolt', about: 'CPU and graphics card at full load together',
      metrics: [
        metric('pw-cpu-temp', 'CPU temperature', '°C', 0, tempRun(r('pw-ct'), 40, cpuPeak + 2, cpuTau), 'max'),
        metric('pw-gpu-temp', 'GPU temperature', '°C', 0, tempRun(r('pw-gt'), 42, gpuPeak + 1, 3), 'max'),
        metric('pw-total', 'CPU + GPU power', 'W', 0, powerRun(r('pw-p'), 42, cpuW * 0.92 + gpuW * 0.97), 'max'),
      ],
    },
  ];

  for (const test of tests) {
    test.errors = 0;
    for (const m of test.metrics) {
      m.summary = m.stat === 'max'
        ? round(Math.max(...m.values), m.dec)
        : round(m.values.slice(1).reduce((a, b) => a + b, 0) / (m.values.length - 1), m.dec);
    }
  }
  const result = { minutes, tests };
  cache.set(rig.slug, result);
  return result;
}

/** 0.5 → "0:30", 12 → "12:00". */
export const clockTime = (min) => `${Math.floor(min)}:${String(Math.round((min % 1) * 60)).padStart(2, '0')}`;
