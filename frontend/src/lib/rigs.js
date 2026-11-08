/**
 * Complete builds for the home page (#rigs).
 *
 * Photos: public/img/rigs/<slug>.webp (full size, for the enlarged view) and
 * <slug>-sm.webp (640 px tall, for the cards). To add a rig, drop both files in
 * and add an entry here.
 *
 * The parts list only what can be seen in the photo. Add the rest (CPU,
 * storage, price) once it's known.
 *   glow    accent colour of the build's lighting (card border and glow)
 *   focus   CSS object-position for the card's crop (optional, default centre)
 *   parts   [kind, text]; kind picks the icon (see RIG_PART_ICONS)
 */
export const RIGS = [
  {
    slug: 'midnight-stealth', name: 'Midnight Stealth', glow: '#94A3B8', cooling: 'Air-cooled',
    blurb: 'An all-black GAMEMAX build with a tower air cooler and three intake fans. Clean and understated.',
    parts: [['Case', 'GAMEMAX case, three front fans'], ['Motherboard', 'Colorful CVN B450'], ['Cooler', 'Tower air cooler']],
  },
  {
    slug: 'neon-aurora', name: 'Neon Aurora', glow: '#8B5CF6', cooling: 'Air-cooled',
    blurb: 'An AORUS air cooler and four RGB memory sticks in blue and pink, over an MSI graphics card.',
    parts: [['GPU', 'MSI graphics card'], ['Cooler', 'Gigabyte AORUS air cooler'], ['RAM', '4 × RGB memory'], ['Cables', 'Red sleeved PCIe cables']],
  },
  {
    slug: 'sakura-white', name: 'Sakura White', glow: '#EC4899', cooling: 'Air-cooled',
    blurb: 'A white build in pink light: MSI Mortar Titanium board, T-Force memory and a Gigabyte graphics card.',
    parts: [['Motherboard', 'MSI Mortar Titanium'], ['GPU', 'Gigabyte graphics card'], ['RAM', 'TEAMGROUP T-Force'], ['PSU', 'Corsair CX550'], ['Case', '1st Player case']],
  },
  {
    slug: 'crimson-shinobi', name: 'Crimson Shinobi', glow: '#EF4444', cooling: 'Air-cooled',
    blurb: 'A white Colorful iGame GeForce RTX card in red light, with a ninja on guard duty.',
    parts: [['GPU', 'Colorful iGame GeForce RTX'], ['Cooler', 'Gigabyte AORUS air cooler'], ['RAM', 'RGB memory']],
  },
  {
    slug: 'blue-frost', name: 'Blue Frost', glow: '#3B82F6', cooling: 'Liquid-cooled',
    blurb: 'An XFX Radeon card under a dual-fan liquid cooler, with four RGB sticks in ice blue.',
    parts: [['GPU', 'XFX Radeon graphics card'], ['Liquid', 'Dual-fan AIO liquid cooler'], ['Motherboard', 'Colorful CVN B450'], ['RAM', '4 × RGB memory']],
  },
  {
    slug: 'red-fury', name: 'Red Fury', glow: '#EF4444', cooling: 'Air-cooled',
    blurb: 'An EVGA GeForce RTX 3070 with an AORUS cooler and four Kingston FURY sticks, all in red.',
    parts: [['GPU', 'EVGA GeForce RTX 3070'], ['Cooler', 'Gigabyte AORUS air cooler'], ['RAM', '4 × Kingston FURY RGB']],
  },
  {
    slug: 'inferno-rog', name: 'Inferno ROG', glow: '#F97316', cooling: 'Liquid-cooled',
    blurb: 'ASUS ROG and G.Skill under a dual-fan liquid cooler, lit like a forge.',
    parts: [['Liquid', 'ASUS ROG dual-fan liquid cooler'], ['RAM', 'G.Skill RGB memory'], ['GPU', 'RGB graphics card']],
  },
  {
    slug: 'ember-ultra', name: 'Ember Ultra', glow: '#F43F5E', cooling: 'Liquid-cooled',
    blurb: 'A Colorful iGame GeForce RTX Ultra under a Thermalright liquid cooler whose pump screen shows CPU temperature.',
    parts: [['GPU', 'Colorful iGame GeForce RTX Ultra'], ['Liquid', 'Thermalright AIO with LCD pump screen'], ['RAM', 'RGB memory']],
  },
  {
    slug: 'violet-strix', name: 'Violet Strix', glow: '#A855F7', cooling: 'Air-cooled', focus: '78% 50%',
    blurb: 'A white case in violet light: ASUS ROG Strix graphics card, MSI Gaming Plus board and a dual-tower cooler.',
    parts: [['GPU', 'ASUS ROG Strix graphics card'], ['Motherboard', 'MSI Gaming Plus'], ['Cooler', 'White dual-tower air cooler'], ['Case', 'White glass case']],
  },
];

export const RIG_PART_ICONS = {
  GPU: 'fa-display', CPU: 'fa-microchip', RAM: 'fa-memory', Motherboard: 'fa-table-cells', PSU: 'fa-plug',
  Storage: 'fa-hard-drive', Cooler: 'fa-fan', Liquid: 'fa-droplet', Case: 'fa-computer', Cables: 'fa-bolt',
};

const base = import.meta.env.BASE_URL;
export const rigPhoto      = (rig) => `${base}img/rigs/${rig.slug}.webp`;
export const rigPhotoSmall = (rig) => `${base}img/rigs/${rig.slug}-sm.webp`;
