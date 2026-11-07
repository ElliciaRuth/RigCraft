<script setup>
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import PublicLayout from '@/layouts/PublicLayout.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';

const route  = useRoute();
const layout = computed(() => route.meta.layout || 'public');

// The stylesheet keys some rules off body.public / body.staff.
watch(layout, (l) => {
  document.body.classList.toggle('public', l !== 'staff');
  document.body.classList.toggle('staff', l === 'staff');
}, { immediate: true });
</script>

<template>
  <PublicLayout v-if="layout === 'public'">
    <RouterView :key="route.path" />
  </PublicLayout>
  <StaffLayout v-else-if="layout === 'staff'">
    <RouterView :key="route.path" />
  </StaffLayout>
  <RouterView v-else />
</template>
