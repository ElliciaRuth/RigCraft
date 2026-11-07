<script setup>
/** Stress-test inputs + live pass/fail preview (intake and re-test). */
import { computed } from 'vue';
import { projectVerdict } from '@/lib/validation.js';
import { BENCHMARK_TOOLS, PASTES } from '@/lib/format.js';

const props = defineProps({
  form:     { type: Object, required: true },     // reactive object holding the test fields
  errorFor: { type: Function, required: true },   // (field) => message | ''
  prefix:   { type: String, default: 't-' },      // keeps element ids unique
});

const verdict = computed(() => projectVerdict(props.form));
</script>

<template>
  <div class="row g-3">
    <div class="col-6 col-md-4">
      <label class="form-label" :for="prefix + 'peak'">Peak Temp (°C)</label>
      <input class="form-control" type="number" :id="prefix + 'peak'" v-model.number="form.peak_temp"
             :class="{ 'is-invalid': errorFor('peak_temp') }" min="0" max="150" step="0.1" placeholder="68.4">
      <div class="invalid-feedback">{{ errorFor('peak_temp') }}</div>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label" :for="prefix + 'fan'">Fan Speed (%)</label>
      <input class="form-control" type="number" :id="prefix + 'fan'" v-model.number="form.fan_speed"
             :class="{ 'is-invalid': errorFor('fan_speed') }" min="0" max="100" step="1" placeholder="62">
      <div class="invalid-feedback">{{ errorFor('fan_speed') }}</div>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label" :for="prefix + 'mem'">Memory Errors</label>
      <input class="form-control" type="number" :id="prefix + 'mem'" v-model.number="form.memory_errors"
             :class="{ 'is-invalid': errorFor('memory_errors') }" min="0" step="1">
      <div class="invalid-feedback">{{ errorFor('memory_errors') }}</div>
    </div>
    <div class="col-6 col-md-8">
      <label class="form-label" :for="prefix + 'tool'">Benchmark Tool</label>
      <select class="form-select" :id="prefix + 'tool'" v-model="form.benchmark_tool">
        <option v-for="t in BENCHMARK_TOOLS" :key="t">{{ t }}</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label" :for="prefix + 'dur'">Duration (min)</label>
      <input class="form-control" type="number" :id="prefix + 'dur'" v-model.number="form.test_duration"
             :class="{ 'is-invalid': errorFor('test_duration') }" min="1" max="1440" step="1">
      <div class="invalid-feedback">{{ errorFor('test_duration') }}</div>
    </div>
    <div class="col-12">
      <div v-if="verdict" class="verdict" :class="verdict.pass ? 'pass' : 'fail'">
        <i class="fa-solid me-1" :class="verdict.pass ? 'fa-circle-check' : 'fa-circle-xmark'"></i>
        <strong>{{ verdict.pass ? 'Will certify — PASSED' : 'Will reject — FAILED' }}</strong> · {{ verdict.detail }}
      </div>
      <div v-else class="verdict"><i class="fa-solid fa-gauge-high me-1"></i>Enter peak temp and memory errors to preview the verdict.</div>
    </div>
    <div class="col-md-7">
      <label class="form-label" :for="prefix + 'paste'">Thermal Paste Applied <span class="text-muted-rc">(blank = not replaced)</span></label>
      <input class="form-control" :id="prefix + 'paste'" :list="prefix + 'paste-list'" v-model="form.paste_brand"
             :class="{ 'is-invalid': errorFor('paste_brand') }" maxlength="60" placeholder="Arctic MX-6">
      <datalist :id="prefix + 'paste-list'"><option v-for="p in PASTES" :key="p" :value="p"></option></datalist>
      <div class="invalid-feedback">{{ errorFor('paste_brand') }}</div>
    </div>
    <div class="col-md-5 d-flex align-items-end">
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" :id="prefix + 'ultra'" v-model="form.ultrasonic_cleaned">
        <label class="form-check-label" :for="prefix + 'ultra'">Ultrasonic PCB cleaning done</label>
      </div>
    </div>
  </div>
</template>
