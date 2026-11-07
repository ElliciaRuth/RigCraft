/**
 * Who is signed in, the CSRF token and the public business rules —
 * loaded from GET /api/session on start and refreshed after every sign-in/out.
 */
import { reactive, computed } from 'vue';

export const session = reactive({
  loaded: false,
  user: null,        // staff: { id, username, full_name, role }
  customer: null,    // shopper: { id, email, full_name, phone }
  canShop: false,    // signed-in shopper with no staff session in this browser
  csrf: '',
  rules: {
    currency: 'PHP',
    temp_ceiling_celsius: 72,
    warranty_months: 6,
    max_warranty: 36,
    max_price: 10_000_000,
    max_cart_items: 10,
    min_password_length: 8,
    categories: ['GPU', 'CPU', 'RAM', 'Motherboard', 'PSU', 'Storage', 'Other'],
    statuses: ['Intake', 'Testing', 'Certified', 'Sold', 'Rejected'],
  },
});

/** Apply a snapshot returned by /api/session or any sign-in/out endpoint. */
export function applySession(s) {
  session.user     = s.user ?? null;
  session.customer = s.customer ?? null;
  session.canShop  = !!s.can_shop;
  if (s.csrf) session.csrf = s.csrf;
  if (s.rules) Object.assign(session.rules, s.rules);
  session.loaded = true;
}

export const isAdmin = computed(() => session.user?.role === 'admin');
