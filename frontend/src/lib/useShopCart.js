/** "Add to cart" state for product cards: who can shop, what's in the cart, and the toast text. */
import { ref, computed, onBeforeUnmount } from 'vue';
import { session } from './session.js';
import { cart } from './cart.js';

export function useShopCart() {
  const cartNotice = ref('');
  let timer = 0;

  function addToCart(p) {
    cartNotice.value = cart.add(p)
      ? `${p.model_name} is in your cart.`
      : `Your cart is full (${session.rules.max_cart_items} items). Check out first, then keep shopping.`;
    clearTimeout(timer);
    timer = setTimeout(() => { cartNotice.value = ''; }, 3500);
  }
  onBeforeUnmount(() => clearTimeout(timer));

  return {
    canShop: computed(() => session.canShop),
    inCart: (hash) => cart.has(hash),
    addToCart,
    cartNotice,
  };
}
