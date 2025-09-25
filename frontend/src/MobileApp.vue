<template>
    <div class="flex flex-col gap-4 p-4">
        <header>
            <h1 class="text-xl font-semibold">Predictive Patterns</h1>
            <p class="text-sm text-stone-500">Interactive risk map optimised for smaller screens.</p>
        </header>

        <HexMap :window-start="windowStart" :window-end="windowEnd" :center="mapCenter" />

        <button
            class="w-full rounded border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-blue-200"
            type="button"
            @click="downloadCsv"
        >
            Export CSV
        </button>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import HexMap from './components/HexMap.vue'

const API_BASE_URL = import.meta.env.VITE_API_URL || '/api/v1/'

const mapCenter = ref([53.4084, -2.9916])
const windowEnd = new Date().toISOString()
const windowStart = new Date(Date.now() - 60 * 60 * 1000).toISOString()

function downloadCsv() {
    window.location.href = `${API_BASE_URL}/export`
}
</script>
