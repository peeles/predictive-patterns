<template>
  <div class="h-dvh flex flex-col">
    <header class="p-3 border-b">
      <h1 class="text-xl font-semibold">Crime Pattern</h1>
    </header>
    <main class="flex-1">
      <HexMap />
    </main>
  </div>
</template>

<script setup lang="ts">
import HexMap from './components/HexMap.vue'
</script>
