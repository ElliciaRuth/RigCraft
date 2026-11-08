<script setup>
/**
 * Staff console layout: left sidebar that collapses to an icon rail on
 * desktop (remembered per browser) and becomes a slide-in drawer on phones.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { session, isAdmin } from '@/lib/session.js';
import { authCall } from '@/lib/api.js';
import { initials, storage } from '@/lib/format.js';

const route  = useRoute();
const router = useRouter();
const root   = document.documentElement;

const groups = computed(() => {
  const g = [{ label: 'Workspace', links: [
    { key: 'dashboard', to: { name: 'dashboard' }, label: 'Dashboard',     icon: 'fa-gauge-high' },
    { key: 'intake',    to: { name: 'intake' },    label: 'New Intake',    icon: 'fa-box-open' },
    { key: 'inventory', to: { name: 'inventory' }, label: 'Inventory',     icon: 'fa-warehouse' },
    { key: 'sales',     to: { name: 'sales' },     label: 'Online orders', icon: 'fa-receipt' },
  ] }];
  if (isAdmin.value) g.push({ label: 'Admin', links: [{ key: 'users', to: { name: 'users' }, label: 'Users', icon: 'fa-users-gear' }] });
  return g;
});

const me       = computed(() => session.user || { full_name: '', username: '', role: '' });
const role     = computed(() => me.value.role ? me.value.role[0].toUpperCase() + me.value.role.slice(1) : '');
const collapsed = ref(root.classList.contains('sb-collapsed'));
const drawer    = ref(false);
const sidebar   = ref(null);

function toggleCollapse() {
  collapsed.value = !collapsed.value;
  root.classList.toggle('sb-collapsed', collapsed.value);
  storage.set('rc.sidebar', collapsed.value ? 'collapsed' : 'expanded');
  setPeek(false);
}

// Collapsed rail "peeks" open (overlaying the page) while hovered, while it
// holds keyboard focus, or while the account menu is open. Desktop only.
let hovering = false, kbdFocus = false, menuOpen = false, peekTimer = 0;
function setPeek(on) {
  clearTimeout(peekTimer);
  root.classList.toggle('sb-peek', on && collapsed.value && mq.matches);
}
function syncPeek(delay) {
  const want = hovering || kbdFocus || menuOpen;
  clearTimeout(peekTimer);
  if (want === root.classList.contains('sb-peek')) return;
  peekTimer = setTimeout(() => setPeek(want), delay);
}
const onEnter    = () => { hovering = true;  syncPeek(120); };
const onLeave    = () => { hovering = false; syncPeek(220); };
const onFocusIn  = (e) => { if (e.target.matches(':focus-visible')) { kbdFocus = true; syncPeek(0); } };
const onFocusOut = (e) => { if (!sidebar.value?.contains(e.relatedTarget)) { kbdFocus = false; syncPeek(220); } };
const onMenuShow = () => { menuOpen = true;  syncPeek(0); };
const onMenuHide = () => { menuOpen = false; syncPeek(220); };
function setDrawer(open) {
  drawer.value = open;
  root.classList.toggle('sb-open', open);
  if (open) sidebar.value?.querySelector('.sb-link')?.focus();
}
const onKey = (e) => { if (e.key === 'Escape' && drawer.value) setDrawer(false); };
const mq = window.matchMedia('(min-width: 992px)');
const onMq = (e) => { if (e.matches) setDrawer(false); else setPeek(false); };

watch(() => route.fullPath, () => setDrawer(false));

onMounted(() => {
  document.addEventListener('keydown', onKey);
  mq.addEventListener('change', onMq);
  // Bootstrap's dropdown events have dots in their names, so bind them here.
  sidebar.value.addEventListener('show.bs.dropdown', onMenuShow);
  sidebar.value.addEventListener('hidden.bs.dropdown', onMenuHide);
  // Enable width transitions only after the initial state has painted.
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('sb-ready')));
});
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  mq.removeEventListener('change', onMq);
  clearTimeout(peekTimer);
  root.classList.remove('sb-open', 'sb-ready', 'sb-peek');
});

async function signOut() {
  await authCall('auth/staff/logout');
  router.push({ name: 'staff-login', query: { bye: 1 } });
}
</script>

<template>
  <!-- Mobile top bar -->
  <header class="staff-topbar d-lg-none">
    <button type="button" class="sb-icon-btn" aria-controls="staffSidebar" :aria-expanded="String(drawer)" aria-label="Open menu" @click="setDrawer(true)">
      <i class="fa-solid fa-bars"></i>
    </button>
    <RouterLink class="rc-logo" :to="{ name: 'dashboard' }"><i class="fa-solid fa-microchip me-1"></i>Rig<span>Craft</span></RouterLink>
    <RouterLink class="avatar-sm text-decoration-none" :to="{ name: 'account' }" title="My account">{{ initials(me.full_name) }}</RouterLink>
  </header>

  <aside ref="sidebar" class="sidebar" id="staffSidebar" aria-label="Console navigation"
         @mouseenter="onEnter" @mouseleave="onLeave" @focusin="onFocusIn" @focusout="onFocusOut">
    <div class="sb-brand">
      <RouterLink class="rc-logo" :to="{ name: 'dashboard' }" title="RigCraft Console">
        <i class="fa-solid fa-microchip"></i><span class="sb-text">Rig<span>Craft</span></span>
      </RouterLink>
      <span class="console-badge sb-text">Console</span>
      <button type="button" class="sb-icon-btn ms-auto d-lg-none" aria-label="Close menu" @click="setDrawer(false)">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <nav class="sb-nav">
      <template v-for="g in groups" :key="g.label">
        <div class="sb-label"><span class="sb-text">{{ g.label }}</span></div>
        <RouterLink v-for="l in g.links" :key="l.key" class="sb-link" :class="{ active: route.meta.nav === l.key }" :to="l.to"
                    :data-label="l.label" :aria-current="route.meta.nav === l.key ? 'page' : null">
          <i class="fa-solid fa-fw" :class="l.icon"></i><span class="sb-text">{{ l.label }}</span>
        </RouterLink>
      </template>

      <div class="sb-label"><span class="sb-text">Storefront</span></div>
      <RouterLink class="sb-link" :to="{ name: 'home' }" target="_blank" rel="noopener" data-label="Public site">
        <i class="fa-solid fa-store fa-fw"></i><span class="sb-text">Public site</span>
        <i class="fa-solid fa-arrow-up-right-from-square sb-ext sb-text"></i>
      </RouterLink>
    </nav>

    <div class="sb-foot">
      <div class="dropup">
        <button type="button" class="sb-user" data-bs-toggle="dropdown" aria-expanded="false" :title="me.full_name">
          <span class="avatar-sm">{{ initials(me.full_name) }}</span>
          <span class="sb-text sb-user-text">
            <span class="sb-user-name">{{ me.full_name }}</span>
            <span class="sb-user-role">{{ role }}</span>
          </span>
          <i class="fa-solid fa-ellipsis-vertical sb-text ms-auto"></i>
        </button>
        <ul class="dropdown-menu">
          <li class="px-3 py-2">
            <div class="fw-semibold">{{ me.full_name }}</div>
            <div class="small text-muted-rc">@{{ me.username }} · {{ role }}</div>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li><RouterLink class="dropdown-item" :class="{ active: route.meta.nav === 'account' }" :to="{ name: 'account' }"><i class="fa-solid fa-user-gear me-2"></i>My account</RouterLink></li>
          <li><button type="button" class="dropdown-item" @click="signOut"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</button></li>
        </ul>
      </div>
      <button type="button" class="sb-collapse d-none d-lg-flex" aria-controls="staffSidebar" :aria-expanded="String(!collapsed)"
              :data-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="toggleCollapse">
        <i class="fa-solid fa-angles-left fa-fw"></i><span class="sb-text">{{ collapsed ? 'Keep open' : 'Collapse' }}</span>
      </button>
    </div>
  </aside>
  <div class="sb-backdrop d-lg-none" aria-hidden="true" @click="setDrawer(false)"></div>

  <slot />

  <footer class="staff-footer mt-auto">
    <div class="container-xl py-3 small d-flex justify-content-between">
      <span>RigCraft Console</span>
      <span>Signed in as {{ me.full_name }}</span>
    </div>
  </footer>
</template>
