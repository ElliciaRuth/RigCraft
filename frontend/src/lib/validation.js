/**
 * Client-side validation that mirrors the CodeIgniter rules (the server
 * re-validates everything). Each function returns { field: message }.
 */
import { session } from './session.js';

export const isBlank = (v) => v === '' || v === null || v === undefined || (typeof v === 'string' && v.trim() === '');

function text(f, e, k, max, msg, required = true) {
  if (isBlank(f[k])) { if (required) e[k] = msg; }
  else if (String(f[k]).trim().length > max) e[k] = `Max ${max} characters.`;
}

function num(f, e, k, min, max, msg, { required = true, integer = false } = {}) {
  if (isBlank(f[k])) { if (required) e[k] = msg; return; }
  const n = Number(f[k]);
  if (!Number.isFinite(n) || n < min || n > max || (integer && !Number.isInteger(n))) e[k] = msg;
}

/** Identity + pricing fields (intake and edit). */
export function validateUnit(f, { withWarranty = false } = {}) {
  const e = {};
  const max = session.rules.max_price;
  text(f, e, 'model_name', 120, 'Enter the full model name.');
  text(f, e, 'brand', 60, 'Brand is required.');
  text(f, e, 'serial_number', 64, "Copy the S/N from the unit's sticker.");
  text(f, e, 'notes', 1000, '', false);
  num(f, e, 'buyout_cost', 0, max, 'Enter what we paid (₱0 – ₱10,000,000).');
  num(f, e, 'listing_price', 0, max, 'Enter the listing price (₱0 – ₱10,000,000).');
  num(f, e, 'retail_price', 0, max, 'Optional: ₱0 – ₱10,000,000.', { required: false });
  if (withWarranty) {
    const mw = session.rules.max_warranty;
    num(f, e, 'warranty_months', 0, mw, `Whole number of months, 0–${mw}.`, { integer: true });
  }
  return e;
}

/** Stress-test result fields (intake and re-test). */
export function validateTest(f) {
  const e = {};
  num(f, e, 'peak_temp', 0, 150, '0–150 °C.');
  num(f, e, 'fan_speed', 0, 100, 'Whole number, 0–100 %.', { integer: true });
  num(f, e, 'memory_errors', 0, 1e6, 'Whole number, 0 or more.', { integer: true, required: false });
  num(f, e, 'test_duration', 1, 1440, '1–1440 minutes.', { integer: true, required: false });
  text(f, e, 'paste_brand', 60, '', false);
  return e;
}

/** Projected verdict for the live preview (same rule as the backend). */
export function projectVerdict(f) {
  if (isBlank(f.peak_temp) || !Number.isFinite(Number(f.peak_temp))) return null;
  const ceiling = session.rules.temp_ceiling_celsius;
  const t = Math.round(Number(f.peak_temp) * 10) / 10;
  const errs = Number(f.memory_errors) || 0;
  const tempOk = t <= ceiling, memOk = errs === 0;
  return {
    pass: tempOk && memOk,
    detail: `${t.toFixed(1)} °C ${tempOk ? '≤' : '>'} ${ceiling.toFixed(1)} °C · ${errs} memory error${errs === 1 ? '' : 's'}`,
  };
}

/** Blank stress-test fields. */
export const blankTest = () => ({
  peak_temp: '', fan_speed: '', memory_errors: 0,
  benchmark_tool: 'FurMark 2', test_duration: 30,
  paste_brand: '', ultrasonic_cleaned: true,
});

/** Shape a form's test fields for the API. */
export const testPayload = (f) => ({
  peak_temp: Number(f.peak_temp),
  fan_speed: Number(f.fan_speed),
  memory_errors: Number(f.memory_errors) || 0,
  benchmark_tool: f.benchmark_tool,
  test_duration: Number(f.test_duration) || 30,
  paste_brand: String(f.paste_brand || '').trim(),
  ultrasonic_cleaned: !!f.ultrasonic_cleaned,
});
