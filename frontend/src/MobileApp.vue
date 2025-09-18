<template>
  <div class="p-2">
    <h1 class="text-lg mb-2">Pattern Prediction Mobile</h1>
    <HexMap :window-start="windowStart" :window-end="windowEnd" :center="mapCenter" />
    <button @click="downloadCsv" class="mt-2 rounded border border-stone-300 px-2 py-1">Export CSV</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import HexMap from './components/HexMap.vue'

const mapCenter = ref([53.4084, -2.9916])
const windowEnd = new Date().toISOString()
const windowStart = new Date(Date.now() - 60 * 60 * 1000).toISOString()

function downloadCsv() {
  const base = import.meta.env.VITE_API_URL || 'http://localhost:8000'
  window.location.href = `${base}/export`
}
</script>
