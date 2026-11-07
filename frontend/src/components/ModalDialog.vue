<script setup>
/** Accessible Bootstrap-styled dialog driven by a boolean. Esc / backdrop close it unless busy. */
import { watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  show:  { type: Boolean, default: false },
  title: { type: String, default: '' },
  size:  { type: String, default: '' },     // '', 'sm', 'lg'
  busy:  { type: Boolean, default: false },  // blocks closing while saving
});
const emit = defineEmits(['close']);

const close = () => { if (!props.busy) emit('close'); };
const onKey = (e) => { if (e.key === 'Escape' && props.show) close(); };

onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  document.body.classList.remove('modal-open');
});
watch(() => props.show, (v) => document.body.classList.toggle('modal-open', v));
</script>

<template>
  <Teleport to="body">
    <template v-if="show">
      <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" :aria-label="title" @click.self="close">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" :class="size ? 'modal-' + size : ''">
          <div class="modal-content rc-modal">
            <div class="modal-header">
              <h2 class="modal-title h5">{{ title }}</h2>
              <button type="button" class="btn-close" aria-label="Close" :disabled="busy" @click="close"></button>
            </div>
            <div class="modal-body"><slot /></div>
            <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop show"></div>
    </template>
  </Teleport>
</template>
