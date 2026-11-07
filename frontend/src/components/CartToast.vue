<script setup>
/** "Added to cart" confirmation that slides up from the bottom. */
defineProps({ message: { type: String, default: '' } });
</script>

<template>
  <Transition name="toast">
    <div v-if="message" class="cart-toast" role="status">
      <i class="fa-solid fa-cart-shopping text-cyan"></i>
      <span>{{ message }}</span>
      <RouterLink :to="{ name: 'cart' }" class="btn btn-sm btn-rc ms-auto">View cart</RouterLink>
    </div>
  </Transition>
</template>
