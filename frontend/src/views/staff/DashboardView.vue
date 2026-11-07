<script setup>
/** Staff dashboard: KPIs, weekly intake chart, work queues and recent activity. */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import Chart from 'chart.js/auto';
import { api } from '@/lib/api.js';
import { session } from '@/lib/session.js';
import { peso, pesoWhole, fmtDate, daysAgo, statusTone, STATUSES } from '@/lib/format.js';

const route = useRoute();
const state = ref('loading');
const error = ref('');
const s     = ref(null);
const weeklyCanvas = ref(null);
let chart = null;

const now       = new Date();
const hour      = now.getHours();
const greeting  = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
const firstName = computed(() => (session.user?.full_name || '').split(' ')[0]);
const today     = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' });

const marginPct = computed(() => {
  const m = s.value?.money;
  if (!m || m.revenue <= 0) return '—';
  return `${Math.round((m.profit / m.revenue) * 100)}%`;
});

const statusRows = computed(() => {
  const counts = s.value?.counts || {};
  const max = Math.max(1, ...Object.values(counts));
  return STATUSES.map(st => ({ status: st, count: counts[st] || 0, tone: statusTone(st), pct: ((counts[st] || 0) / max) * 100 }));
});

const unitLink = (hash, retest = false) => ({ name: 'unit', params: { hash }, query: retest ? { retest: 1 } : {} });
const inventoryLink = (status) => ({ name: 'inventory', query: { status } });

function drawWeekly() {
  if (!weeklyCanvas.value) return;
  const weeks  = s.value.weekly;
  const labels = weeks.map(w => new Date(w.week_start + 'T00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }));

  Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
  Chart.defaults.color = '#94A3B8';

  const bar = (label, key, color) => ({
    label, data: weeks.map(w => w[key]), backgroundColor: color,
    borderRadius: 4, borderSkipped: false, maxBarThickness: 34,
  });

  chart?.destroy();
  chart = new Chart(weeklyCanvas.value, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        bar('Passed', 'passed', '#10B981'),
        bar('Failed', 'failed', '#EF4444'),
        bar('Not yet tested', 'untested', '#F59E0B'),
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, useBorderRadius: true, borderRadius: 2 } },
        tooltip: {
          backgroundColor: '#0F172A', borderColor: '#334155', borderWidth: 1, padding: 10,
          titleColor: '#E2E8F0', bodyColor: '#E2E8F0',
          callbacks: { title: (items) => `Week of ${items[0].label}` },
        },
      },
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(148,163,184,.12)' } },
      },
    },
  });
}

onMounted(async () => {
  try {
    s.value = await api.get('stats');
    state.value = 'ready';
    await nextTick();
    drawWeekly();
  } catch (e) {
    error.value = e.message;
    state.value = 'error';
  }
});
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
  <main class="flex-grow-1">
    <div class="container-xl py-4">
      <div v-if="route.query.denied" class="alert alert-warning py-2"><i class="fa-solid fa-lock me-1"></i>That page is for administrators only.</div>

      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
          <h1 class="page-title">{{ greeting }}, {{ firstName }}</h1>
          <p class="page-sub mb-0">Here's how the workshop is doing today, {{ today }}.</p>
        </div>
        <div class="d-flex gap-2">
          <RouterLink :to="{ name: 'intake' }" class="btn btn-rc"><i class="fa-solid fa-plus me-1"></i>New intake</RouterLink>
          <RouterLink :to="{ name: 'inventory' }" class="btn btn-outline-light"><i class="fa-solid fa-warehouse me-1"></i>Inventory</RouterLink>
        </div>
      </div>

      <div v-if="state === 'loading'" class="empty-state"><div class="spinner-border text-info"></div></div>
      <div v-else-if="state === 'error'" class="alert alert-danger">{{ error }}</div>

      <template v-else>
        <!-- KPIs -->
        <section class="row g-3 mb-3">
          <div class="col-6 col-xl-3">
            <RouterLink class="rc-card fill kpi d-block text-reset text-decoration-none" :to="inventoryLink('Certified')">
              <span class="kpi-icon cyan"><i class="fa-solid fa-boxes-stacked"></i></span>
              <div class="kpi-label">In stock (certified)</div>
              <div class="kpi-value">{{ s.counts.Certified }}</div>
              <div class="kpi-sub">{{ pesoWhole(s.money.stock_value) }} listed value</div>
            </RouterLink>
          </div>
          <div class="col-6 col-xl-3">
            <RouterLink class="rc-card fill kpi d-block text-reset text-decoration-none" :to="inventoryLink('Sold')">
              <span class="kpi-icon emerald"><i class="fa-solid fa-peso-sign"></i></span>
              <div class="kpi-label">Revenue</div>
              <div class="kpi-value">{{ pesoWhole(s.money.revenue) }}</div>
              <div class="kpi-sub">{{ s.counts.Sold }} sold · {{ pesoWhole(s.money.revenue_30d) }} in 30 days</div>
            </RouterLink>
          </div>
          <div class="col-6 col-xl-3">
            <div class="rc-card fill kpi">
              <span class="kpi-icon emerald"><i class="fa-solid fa-chart-line"></i></span>
              <div class="kpi-label">Gross profit</div>
              <div class="kpi-value" :class="s.money.profit >= 0 ? 'text-emerald' : 'text-red'">{{ pesoWhole(s.money.profit) }}</div>
              <div class="kpi-sub">{{ marginPct }} margin on sold units</div>
            </div>
          </div>
          <div class="col-6 col-xl-3">
            <div class="rc-card fill kpi">
              <span class="kpi-icon amber"><i class="fa-solid fa-temperature-half"></i></span>
              <div class="kpi-label">Stress-test pass rate</div>
              <div class="kpi-value">{{ s.tests.pass_rate === null ? '—' : s.tests.pass_rate + '%' }}</div>
              <div class="kpi-sub">{{ s.tests.runs }} runs · avg pass {{ s.tests.avg_pass_temp ?? '—' }} °C</div>
            </div>
          </div>
        </section>

        <!-- Chart + status breakdown -->
        <section class="row g-3 mb-3">
          <div class="col-lg-8">
            <div class="rc-card fill">
              <div class="d-flex justify-content-between align-items-baseline">
                <div class="rc-card-title">Intake by week</div>
                <span class="small text-muted-rc">Last 8 weeks · by latest test result</span>
              </div>
              <div class="chart-wrap mt-3" style="height: 260px"><canvas ref="weeklyCanvas" role="img" aria-label="Units taken in per week"></canvas></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="rc-card fill">
              <div class="rc-card-title mb-3">Units by status</div>
              <RouterLink v-for="row in statusRows" :key="row.status" class="bar-row text-reset text-decoration-none" :to="inventoryLink(row.status)">
                <span>{{ row.status }}</span>
                <div class="bar-track"><div class="bar-fill" :class="row.tone" :style="{ width: row.pct + '%' }"></div></div>
                <span class="text-end tabular">{{ row.count }}</span>
              </RouterLink>
              <hr style="border-color: var(--rc-border)">
              <div class="small d-flex justify-content-between"><span class="text-muted-rc">Capital in stock</span><span class="tabular">{{ peso(s.money.stock_cost) }}</span></div>
              <div class="small d-flex justify-content-between mt-1"><span class="text-muted-rc">Awaiting test</span><span class="tabular">{{ peso(s.money.pending_cost) }}</span></div>
              <div class="small d-flex justify-content-between mt-1"><span class="text-muted-rc">Written off (rejected)</span><span class="tabular text-red">{{ peso(s.money.rejected_cost) }}</span></div>
            </div>
          </div>
        </section>

        <!-- Queues and activity -->
        <section class="row g-3">
          <div class="col-lg-4">
            <div class="rc-card fill">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="rc-card-title">Awaiting stress test</div>
                <span class="tag pending">{{ s.counts.Intake + s.counts.Testing }}</span>
              </div>
              <ul v-if="s.awaiting.length" class="activity">
                <li v-for="u in s.awaiting" :key="u.serial_hash">
                  <span class="dot pending"></span>
                  <div class="min-w-0 flex-grow-1">
                    <RouterLink :to="unitLink(u.serial_hash)" class="d-block text-truncate">{{ u.model_name }}</RouterLink>
                    <span class="small text-muted-rc">{{ u.status }} · arrived {{ daysAgo(u.intake_date) }}</span>
                  </div>
                  <RouterLink :to="unitLink(u.serial_hash, true)" class="btn btn-sm btn-outline-info flex-shrink-0">Log test</RouterLink>
                </li>
              </ul>
              <div v-else class="empty-state py-4"><i class="fa-solid fa-mug-hot"></i><p class="mt-2 mb-0 small">Queue is clear.</p></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="rc-card fill">
              <div class="rc-card-title mb-2">Recent stress tests</div>
              <ul v-if="s.recent_tests.length" class="activity">
                <li v-for="t in s.recent_tests" :key="t.serial_hash + t.test_date">
                  <span class="dot" :class="t.stress_status === 'PASSED' ? 'pass' : 'fail'"></span>
                  <div class="min-w-0 flex-grow-1">
                    <RouterLink :to="unitLink(t.serial_hash)" class="d-block text-truncate">{{ t.model_name }}</RouterLink>
                    <span class="small text-muted-rc">{{ t.tested_by }} · {{ daysAgo(t.test_date) }}</span>
                  </div>
                  <span class="small tabular flex-shrink-0" :class="t.stress_status === 'PASSED' ? 'text-emerald' : 'text-red'">{{ t.peak_temp_celsius.toFixed(1) }} °C</span>
                </li>
              </ul>
              <div v-else class="empty-state py-4"><p class="mb-0 small">No tests logged yet.</p></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="rc-card fill">
              <div class="rc-card-title mb-2">Recent sales</div>
              <ul v-if="s.recent_sales.length" class="activity">
                <li v-for="x in s.recent_sales" :key="x.serial_hash">
                  <span class="dot pass"></span>
                  <div class="min-w-0 flex-grow-1">
                    <RouterLink :to="unitLink(x.serial_hash)" class="d-block text-truncate">{{ x.model_name }}</RouterLink>
                    <span class="small text-muted-rc">{{ fmtDate(x.sold_at) }} · profit {{ pesoWhole(x.sold_price - x.buyout_cost) }}</span>
                  </div>
                  <span class="small tabular flex-shrink-0">{{ pesoWhole(x.sold_price) }}</span>
                </li>
              </ul>
              <div v-else class="empty-state py-4"><p class="mb-0 small">No sales recorded yet.</p></div>
            </div>
          </div>
        </section>
      </template>
    </div>
  </main>
</template>
