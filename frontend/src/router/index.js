/**
 * Routes and access guards.
 *
 * meta.layout  'public' (store nav + footer) | 'staff' (console sidebar) | 'bare'
 * meta.auth    'staff' | 'admin' | 'customer' | 'shopper' (customer, no staff session)
 * meta.guest   'staff' | 'customer' — sign-in pages skip ahead when already signed in
 * meta.nav     which nav item to highlight
 *
 * The server checks every API call too; these guards just route people to
 * the right page.
 */
import { createRouter, createWebHistory } from 'vue-router';
import { session } from '@/lib/session.js';
import HomeView from '@/views/public/HomeView.vue';

const pub   = (name, path, component, meta = {}) => ({ name, path, component, meta: { layout: 'public', ...meta } });
const staff = (name, path, component, meta = {}) => ({ name, path, component, meta: { layout: 'staff', auth: 'staff', ...meta } });

const routes = [
  // ---- Public store ----
  pub('home',     '/',         HomeView,                                       { nav: 'home', title: 'Certified Pre-Owned PC Parts' }),
  pub('shop',     '/shop',     () => import('@/views/public/ShopView.vue'),    { nav: 'shop', title: 'Shop certified parts' }),
  pub('verify',   '/verify',   () => import('@/views/public/VerifyView.vue'),  { nav: 'verify', title: 'Verify a Unit' }),
  pub('cart',     '/cart',     () => import('@/views/public/CartView.vue'),    { nav: 'cart', title: 'Your cart', auth: 'shopper' }),
  pub('checkout', '/checkout', () => import('@/views/public/CheckoutView.vue'), { nav: 'cart', title: 'Checkout', auth: 'shopper' }),
  pub('orders',   '/orders',   () => import('@/views/public/OrdersView.vue'),  { nav: 'orders', title: 'My orders', auth: 'customer' }),
  pub('signin',   '/signin',   () => import('@/views/public/SignInView.vue'),  { title: 'Sign in', guest: 'customer' }),
  pub('register', '/register', () => import('@/views/public/RegisterView.vue'), { title: 'Create an account', guest: 'customer' }),

  // ---- Staff console ----
  { name: 'staff-login', path: '/staff/login', component: () => import('@/views/staff/LoginView.vue'),
    meta: { layout: 'bare', guest: 'staff', title: 'Staff Login' } },
  staff('dashboard', '/staff',             () => import('@/views/staff/DashboardView.vue'), { nav: 'dashboard', title: 'Dashboard' }),
  staff('intake',    '/staff/intake',      () => import('@/views/staff/IntakeView.vue'),    { nav: 'intake', title: 'New Intake' }),
  staff('inventory', '/staff/inventory',   () => import('@/views/staff/InventoryView.vue'), { nav: 'inventory', title: 'Inventory' }),
  staff('unit',      '/staff/units/:hash', () => import('@/views/staff/UnitView.vue'),      { nav: 'inventory', title: 'Unit' }),
  staff('sales',     '/staff/orders',      () => import('@/views/staff/SalesView.vue'),     { nav: 'sales', title: 'Online orders' }),
  staff('users',     '/staff/users',       () => import('@/views/staff/UsersView.vue'),     { nav: 'users', title: 'Users', auth: 'admin' }),
  staff('account',   '/staff/account',     () => import('@/views/staff/AccountView.vue'),   { nav: 'account', title: 'My Account' }),

  // ---- Old .php addresses (bookmarks, printed QR labels) ----
  { path: '/frontend/:page(.*)', redirect: legacyRedirect },
  { path: '/index.php', redirect: '/' },
  // The XAMPP copy lived under /RigCraft/, so its QR labels and bookmarks read
  // …/RigCraft/verify?hash=X or …/RigCraft/frontend/verify.php?hash=X. Where the app
  // is served from the site root, drop the prefix (old .php pages then go through
  // the redirect above).
  { path: '/RigCraft/:rest(.*)*', redirect: (to) => ({ path: '/' + [].concat(to.params.rest || []).join('/'), query: to.query, hash: to.hash }) },

  pub('not-found', '/:pathMatch(.*)*', () => import('@/views/public/NotFoundView.vue'), { title: 'Page not found' }),
];

/** frontend/verify.php?hash=X → /verify?hash=X, frontend/unit.php?hash=X → /staff/units/X, … */
function legacyRedirect(to) {
  const page = String(to.params.page || '').replace(/\.php$/, '') || 'index';
  const q = { ...to.query };
  const map = {
    index: '/', shop: '/shop', verify: '/verify', cart: '/cart', checkout: '/checkout', orders: '/orders',
    signin: '/signin', register: '/register', login: '/staff/login', dashboard: '/staff', intake: '/staff/intake',
    inventory: '/staff/inventory', sales: '/staff/orders', users: '/staff/users', account: '/staff/account',
  };
  if (page === 'unit' && q.hash) {
    const hash = q.hash; delete q.hash;
    return { name: 'unit', params: { hash }, query: q };
  }
  return { path: map[page] || '/', query: q, hash: to.hash };
}

/** Only follow ?next= to our own pages (prevents open redirects). */
export function safeNext(next, fallback) {
  return typeof next === 'string' && next.startsWith('/') && !next.startsWith('//') ? next : fallback;
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior(to, from, saved) {
    if (saved) return saved;
    if (to.hash) return { el: to.hash, top: 72, behavior: 'smooth' };
    if (to.path === from.path) return false;   // query-only changes (filters) keep the scroll position
    return { top: 0 };
  },
});

router.beforeEach((to) => {
  const { auth, guest } = to.meta;

  if (guest === 'staff' && session.user) return safeNext(to.query.next, '/staff');
  if (guest === 'customer' && session.customer) return safeNext(to.query.next, '/');

  if (auth === 'staff' || auth === 'admin') {
    if (!session.user) return { name: 'staff-login', query: { next: to.fullPath } };
    if (auth === 'admin' && session.user.role !== 'admin') return { name: 'dashboard', query: { denied: 1 } };
  }
  if (auth === 'shopper' && session.user) return { name: 'home' };   // staff don't shop
  if ((auth === 'customer' || auth === 'shopper') && !session.customer) {
    return { name: 'signin', query: { next: to.fullPath } };
  }
  return true;
});

router.afterEach((to, from) => {
  // Query-only changes (filters, ?compare=) keep the title a page may have set itself.
  if (from.matched.length && from.path === to.path) return;
  document.title = to.meta.title ? `${to.meta.title} · RigCraft` : 'RigCraft';
});

export default router;
