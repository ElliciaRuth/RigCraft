/**
 * Shared constants and formatters (Philippine peso, Philippine locale).
 */

export const DEMO_HASH = 'RC-3070TI-8941';   // seeded demo unit offered on the verify page

export const CATEGORIES      = ['GPU', 'CPU', 'RAM', 'Motherboard', 'PSU', 'Storage', 'Other'];
export const STATUSES        = ['Intake', 'Testing', 'Certified', 'Sold', 'Rejected'];
export const BENCHMARK_TOOLS = ['FurMark 2', 'OCCT', '3DMark Time Spy', 'Prime95', 'Cinebench R23', 'MemTest86'];
export const BRANDS          = ['NVIDIA', 'AMD', 'Intel', 'ASUS', 'MSI', 'Gigabyte', 'EVGA', 'Zotac', 'Sapphire', 'Corsair'];
export const PASTES          = ['Arctic MX-6', 'Arctic MX-4', 'Thermal Grizzly Kryonaut', 'Noctua NT-H2', 'Gelid GC-Extreme', 'Honeywell PTM7950'];

export const CATEGORY_ICONS = {
  GPU: 'fa-display', CPU: 'fa-microchip', RAM: 'fa-memory', Motherboard: 'fa-table-cells',
  PSU: 'fa-plug', Storage: 'fa-hard-drive', Other: 'fa-puzzle-piece',
};
export const categoryIcon = (cat) => CATEGORY_ICONS[cat] || 'fa-puzzle-piece';

export const STATUS_ICONS = {
  Intake: 'fa-inbox', Testing: 'fa-hourglass-half', Certified: 'fa-circle-check',
  Sold: 'fa-receipt', Rejected: 'fa-circle-xmark',
};

/** Map a component status to a visual tone used by .status-pill / .tag classes. */
export function statusTone(status) {
  if (status === 'Certified' || status === 'Sold') return 'pass';
  if (status === 'Rejected') return 'fail';
  return 'pending';
}

// ---------------------------------------------------------------------
// Money & dates
// ---------------------------------------------------------------------
const pesoFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const pesoShort  = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });

/** 19999 -> "₱19,999.00" */
export const peso = (amount) => pesoFormat.format(Number(amount) || 0);
/** 19999 -> "₱19,999" */
export const pesoWhole = (amount) => pesoShort.format(Number(amount) || 0);

const toDate = (v) => new Date(String(v).replace(' ', 'T'));

/** "2026-10-30 14:22:00" -> "Oct 30, 2026" */
export function fmtDate(value) {
  if (!value) return '—';
  const d = toDate(value);
  return isNaN(d) ? value : d.toLocaleDateString('en-PH', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "2026-10-30 14:22:00" -> "Oct 30, 2026, 2:22 PM" */
export function fmtDateTime(value) {
  if (!value) return '—';
  const d = toDate(value);
  return isNaN(d) ? value : d.toLocaleString('en-PH', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** "today", "yesterday", "5 days ago" */
export function daysAgo(value) {
  if (!value) return '';
  const d = toDate(value);
  if (isNaN(d)) return '';
  const days = Math.floor((Date.now() - d.getTime()) / 86400000);
  if (days <= 0) return 'today';
  if (days === 1) return 'yesterday';
  if (days < 30) return `${days} days ago`;
  const months = Math.floor(days / 30);
  return months === 1 ? '1 month ago' : `${months} months ago`;
}

/** Today as YYYY-MM-DD in local time. */
export function todayIso() {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

/** "Marcus Reyes" -> "MR" */
export const initials = (name) =>
  String(name || '').trim().split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase() || '?';

export function debounce(fn, ms = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

// ---------------------------------------------------------------------
// Browser helpers
// ---------------------------------------------------------------------

/** localStorage that never throws (private mode / blocked storage). */
export const storage = {
  get(key)        { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch { /* ignore */ } },
  remove(key)     { try { localStorage.removeItem(key); } catch { /* ignore */ } },
};

/** Copy text to the clipboard, with a fallback for plain-HTTP LAN access. */
export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const ta = Object.assign(document.createElement('textarea'), { value: text });
    document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove();
  }
}

// ---------------------------------------------------------------------
// QR target: the verify-page URL printed in QR labels.
// Phones can't open 127.0.0.1, so staff can point labels at the PC's LAN address.
// ---------------------------------------------------------------------
export const QR_BASE_KEY = 'rc.qrBase.v3';
export const defaultVerifyBase = () => new URL(`${import.meta.env.BASE_URL}verify`, location.origin).href;

/** Public verify URL for a hash (honours the QR-target override). */
export function verifyUrlFor(hash, base = storage.get(QR_BASE_KEY)) {
  let u;
  try { u = new URL(base || defaultVerifyBase(), location.href); }
  catch { u = new URL(defaultVerifyBase()); }
  u.searchParams.set('hash', hash);
  return u.href;
}
