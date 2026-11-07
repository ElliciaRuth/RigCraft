/**
 * Product photos: public/img/products/<slug>.webp, slug = model name.
 * Transparent cut-outs, so they sit on the dark cards.
 */

/** Models that have a photo. Add the slug here after dropping in a new .webp. */
export const PRODUCT_PHOTOS = new Set([
  'amd-radeon-rx-6600',
  'nvidia-geforce-rtx-3070-ti',
  'nvidia-geforce-rtx-3080-10gb',
  'amd-ryzen-7-5800x',
  'corsair-vengeance-lpx-32gb-ddr4-3200',
  // Extended catalogue (backend/app/Database/Seeds/CatalogData.php)
  'adata-ultimate-su650-512gb',
  'amd-ryzen-5-3400g',
  'amd-ryzen-5-5500',
  'amd-ryzen-5-7500f',
  'amd-ryzen-7-5700x',
  'asrock-radeon-rx-7600-challenger-white-8gb',
  'asrock-radeon-rx-7600-xt-challenger-16gb-oc',
  'asrock-b450m-steel-legend',
  'asrock-b550m-pro-rs',
  'asus-dual-radeon-rx-7600-v2-oc-8gb',
  'colorful-igame-geforce-rtx-3060-ti-advanced-oc',
  'colorful-igame-geforce-rtx-4070-super-ultra-w-oc',
  'g-skill-flare-x5-32gb-ddr5-6000',
  'g-skill-ripjaws-v-16gb-ddr4-3200',
  'gigabyte-a520m-ds3h-ac',
  'gigabyte-b550m-k',
  'gigabyte-geforce-gtx-1650-mini-itx-oc-4g',
  'gigabyte-geforce-rtx-4060-windforce-oc-8g',
  'intel-core-i5-14400f',
  'kingston-fury-beast-16gb-ddr4-3200',
  'lexar-nm620-512gb-nvme-m-2',
  'msi-b450m-a-pro-max-ii',
  'palit-geforce-rtx-3050-stormx-6gb',
  'samsung-870-evo-500gb',
  'samsung-990-pro-1tb-nvme-m-2',
  'sapphire-pure-radeon-rx-7800-xt-16gb',
  'teamgroup-mp33-512gb-nvme-m-2',
  'teamgroup-t-force-delta-rgb-16gb-ddr4-3200',
  'teamgroup-t-force-vulcan-z-16gb-ddr4-3200',
  'xfx-speedster-qick-319-radeon-rx-7800-xt-16gb',
]);

/** "NVIDIA GeForce RTX 3070 Ti" -> "nvidia-geforce-rtx-3070-ti" */
export const photoSlug = (modelName) =>
  String(modelName || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** Photo URL for a model, or null when there isn't one (show the category icon instead). */
export function productPhoto(modelName) {
  const slug = photoSlug(modelName);
  return PRODUCT_PHOTOS.has(slug) ? `${import.meta.env.BASE_URL}img/products/${slug}.webp` : null;
}
