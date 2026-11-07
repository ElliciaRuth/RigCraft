<template>
  <main class="flex-grow-1">
    <div class="state-box">
      <div class="state-icon text-cyan mb-3"><i class="fa-solid fa-compass"></i></div>
      <h1 class="h4 fw-bold">Page not found</h1>
      <p class="text-muted-rc">That page doesn't exist, or it moved when the store was updated.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
        <RouterLink :to="{ name: 'home' }" class="btn btn-rc btn-sm"><i class="fa-solid fa-house me-1"></i>Home</RouterLink>
        <RouterLink :to="{ name: 'shop' }" class="btn btn-outline-info btn-sm">Shop</RouterLink>
        <RouterLink :to="{ name: 'verify' }" class="btn btn-outline-info btn-sm">Verify a unit</RouterLink>
      </div>
    </div>
  </main>
</template>
