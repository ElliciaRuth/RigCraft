/**
 * Cart — signed-in shoppers only (visitors browse, staff don't shop).
 * Kept in this browser under the shopper's own key, so people sharing a
 * computer never see each other's cart. Each unit is one physical item, so the
 * cart is a list of units (no quantities). Prices here are for display only;
 * the server re-checks everything at checkout.
 */
import { ref, computed, watch } from 'vue';
import { session } from './session.js';
import { storage } from './format.js';
import { productPhoto } from './photos.js';
import { api } from './api.js';

const key = computed(() => (session.canShop && session.customer?.id ? `rc.cart.c${session.customer.id}` : null));
const items = ref([]);   // [{ hash, model_name, brand, category, price }]

function read() {
  if (!key.value) return [];
  try {
    const list = JSON.parse(storage.get(key.value) || '[]');
    return Array.isArray(list) ? list.filter(i => i && typeof i.hash === 'string') : [];
  } catch { return []; }
}

function save(list) {
  items.value = list;
  if (!key.value) return;
  if (list.length) storage.set(key.value, JSON.stringify(list));
  else storage.remove(key.value);
}

// Switch carts when a different shopper signs in; follow changes from other tabs.
watch(key, () => { items.value = read(); }, { immediate: true });
window.addEventListener('storage', (e) => { if (e.key === key.value) items.value = read(); });

export const cart = {
  items,
  count: computed(() => items.value.length),
  has: (hash) => items.value.some(i => i.hash === hash),
  /** Add a unit (a row from /api/shop or /api/verify). Returns false when the cart is full. */
  add(unit) {
    if (!key.value) return false;
    const hash = unit.serial_hash;
    if (items.value.some(i => i.hash === hash)) return true;
    if (items.value.length >= session.rules.max_cart_items) return false;
    save([...items.value, {
      hash, model_name: unit.model_name, brand: unit.brand,
      category: unit.category, price: unit.listing_price,
    }]);
    return true;
  },
  remove(hash) { save(items.value.filter(i => i.hash !== hash)); },
  clear() { save([]); },
  reload() { items.value = read(); },
};

/**
 * Merge the stored cart with live data from /api/cart.
 * Used by the cart and checkout pages. Returns { items, priceChanged }.
 */
export async function loadCart() {
  const stored = read();
  if (!stored.length) return { items: [], priceChanged: false };

  const data = await api.get('cart', { hashes: stored.map(i => i.hash).join(',') });
  const live = new Map(data.items.map(i => [i.serial_hash, i]));

  let priceChanged = false;
  const merged = stored.map((s) => {
    const l = live.get(s.hash);
    if (!l) return { ...s, available: false, photo: productPhoto(s.model_name) };
    if (l.available && Number(s.price) !== l.listing_price) priceChanged = true;
    return {
      hash: s.hash, model_name: l.model_name, brand: l.brand, category: l.category,
      price: l.listing_price, retail_price: l.retail_price, warranty_months: l.warranty_months,
      available: l.available, photo: productPhoto(l.model_name),
    };
  });

  // Keep the stored snapshot current so the next visit shows the right price.
  save(merged.map(({ hash, model_name, brand, category, price }) => ({ hash, model_name, brand, category, price })));
  return { items: merged, priceChanged };
}
