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
]);

/** "NVIDIA GeForce RTX 3070 Ti" -> "nvidia-geforce-rtx-3070-ti" */
export const photoSlug = (modelName) =>
  String(modelName || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** Photo URL for a model, or null when there isn't one (show the category icon instead). */
export function productPhoto(modelName) {
  const slug = photoSlug(modelName);
  return PRODUCT_PHOTOS.has(slug) ? `${import.meta.env.BASE_URL}img/products/${slug}.webp` : null;
}
