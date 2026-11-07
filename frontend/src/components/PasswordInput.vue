<script setup>
/** Password field with a show/hide (eye) toggle. Use like an <input>: v-model, id, class, etc. */
import { ref } from 'vue';

defineOptions({ inheritAttrs: false });
const model = defineModel({ type: String, default: '' });
const shown = ref(false);
</script>

<template>
  <div class="pw-field">
    <input v-bind="$attrs" v-model="model" :type="shown ? 'text' : 'password'">
    <button type="button" class="pw-toggle" :aria-pressed="String(shown)" :aria-label="shown ? 'Hide password' : 'Show password'" @click="shown = !shown">
      <i class="fa-solid" :class="shown ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i>
    </button>
  </div>
</template>
