<script setup>
/** Printable QR label (the page's print stylesheet prints only #print-label). */
import { ref, computed, watch, onMounted } from 'vue';
import QRCode from 'qrcode';
import { peso, statusTone } from '@/lib/format.js';

const props = defineProps({
  hash:   { type: String, required: true },
  status: { type: String, default: '' },
  model:  { type: String, default: '' },
  price:  { type: Number, default: 0 },
  url:    { type: String, required: true },
});

const canvas  = ref(null);
const tone    = computed(() => statusTone(props.status));
const heading = computed(() =>
  tone.value === 'pass' ? 'RigCraft Certified' : tone.value === 'fail' ? 'RigCraft · Rejected' : 'RigCraft · In Testing');

async function draw() {
  if (!canvas.value || !props.url) return;
  await QRCode.toCanvas(canvas.value, props.url, {
    width: 200, margin: 0, errorCorrectionLevel: 'M',
    color: { dark: '#0F172A', light: '#FFFFFF' },
  });
}
onMounted(draw);
watch(() => props.url, draw);
</script>

<template>
  <div class="qr-label" id="print-label">
    <div class="lbl-brand">{{ heading }}</div>
    <div class="qr-box" role="img" :aria-label="'QR code for ' + hash"><canvas ref="canvas"></canvas></div>
    <div class="lbl-hash">{{ hash }}</div>
    <div class="lbl-model">{{ model }}</div>
    <div v-if="tone === 'pass' && price > 0" class="lbl-price">{{ peso(price) }}</div>
  </div>
</template>
