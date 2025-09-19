<template>
    <div class="flex h-screen flex-row bg-slate-50 text-slate-900">
        <aside class="flex w-96 flex-col gap-4 border-r border-slate-200 bg-white p-6 shadow-sm">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight">Predictive Patterns</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Configure the temporal window and filters to explore crime risk across the selected map region.
                </p>
            </header>

            <form class="flex flex-col gap-4" @submit.prevent>
                <fieldset class="flex flex-col gap-2">
                    <label for="datetime" class="text-sm font-medium">Observation window end</label>
                    <input
                        id="datetime"
                        v-model="selectedDate"
                        class="rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        type="datetime-local"
                    />
                    <p class="text-xs text-slate-500">All predictions are calculated using the trailing window from this timestamp.</p>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label for="interval" class="text-sm font-medium">Interval</label>
                    <select
                        id="interval"
                        v-model="intervalType"
                        class="rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    >
                        <option value="hour">Hourly</option>
                        <option value="day">Daily</option>
                        <option value="week">Weekly</option>
                    </select>
                    <div class="flex items-center gap-3 text-sm">
                        <label class="text-sm font-medium" for="playback">Playback offset</label>
                        <input id="playback" v-model.number="playback" :max="playbackMax" min="0" step="1" type="range" />
                        <span class="w-10 text-right font-mono">{{ playbackLabel }}</span>
                    </div>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label for="search" class="text-sm font-medium">Search location</label>
                    <div class="flex gap-2">
                        <input
                            id="search"
                            v-model.trim="searchQuery"
                            class="flex-1 rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                            placeholder="Postcode or neighbourhood"
                            type="text"
                            @keyup.enter="searchLocation"
                        />
                        <button
                            class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200"
                            type="button"
                            @click="searchLocation"
                        >
                            Go
                        </button>
                    </div>
                    <p v-if="searchError" class="text-xs text-rose-600">{{ searchError }}</p>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label for="crime-type" class="text-sm font-medium">Crime type</label>
                    <select
                        id="crime-type"
                        v-model="crimeType"
                        class="rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    >
                        <option value="all">All</option>
                        <option value="burglary">Burglary</option>
                        <option value="assault">Assault</option>
                        <option value="theft">Theft</option>
                        <option value="vehicle-crime">Vehicle crime</option>
                        <option value="anti-social-behaviour">Anti-social behaviour</option>
                    </select>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label for="district" class="text-sm font-medium">District</label>
                    <input
                        id="district"
                        v-model.trim="district"
                        class="rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        placeholder="Optional district filter"
                        type="text"
                    />
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <label for="custom-layer" class="text-sm font-medium">Custom layer</label>
                    <input
                        id="custom-layer"
                        v-model.trim="customLayer"
                        class="rounded border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        placeholder="e.g. schools"
                        type="text"
                    />
                </fieldset>

                <NLQConsole />

                <button
                    class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    type="button"
                    @click="downloadCsv"
                >
                    Export CSV
                </button>
            </form>
        </aside>

        <main class="flex flex-1 flex-col">
            <HexMap
                :center="mapCenter"
                :crime-type="crimeType"
                :custom-layer="customLayer"
                :district="district"
                :window-end="windowEnd"
                :window-start="windowStart"
            />
        </main>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import HexMap from './components/HexMap.vue'
import NLQConsole from './components/NLQConsole.vue'

const API_BASE_URL = import.meta.env.VITE_API_URL;

const selectedDate = ref(new Date().toISOString().slice(0, 16))
const intervalType = ref('hour')
const playback = ref(0)
const searchQuery = ref('')
const searchError = ref('')
const mapCenter = ref([53.4084, -2.9916])
const crimeType = ref('all')
const district = ref('')
const customLayer = ref('')

const playbackMax = computed(() => {
    switch (intervalType.value) {
        case 'day':
            return 30
        case 'week':
            return 52
        default:
            return 168
    }
})

const playbackLabel = computed(() => {
    const unit = intervalType.value === 'hour' ? 'h' : intervalType.value === 'day' ? 'd' : 'w'
    return `${playback.value}${unit}`
})

const windowEnd = computed(() => {
    const dt = new Date(selectedDate.value)

    if (intervalType.value === 'day') {
        dt.setDate(dt.getDate() - playback.value)
    } else if (intervalType.value === 'week') {
        dt.setDate(dt.getDate() - playback.value * 7)
    } else {
        dt.setHours(dt.getHours() - playback.value)
    }

    return dt.toISOString()
})

const windowStart = computed(() => {
    const start = new Date(windowEnd.value)

    if (intervalType.value === 'day') {
        start.setDate(start.getDate() - 1)
    } else if (intervalType.value === 'week') {
        start.setDate(start.getDate() - 7)
    } else {
        start.setHours(start.getHours() - 1)
    }

    return start.toISOString()
})

async function searchLocation() {
    searchError.value = ''
    if (!searchQuery.value) {
        searchError.value = 'Enter a location to search.'
        return
    }

    try {
        const resp = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchQuery.value)}`
        )

        if (!resp.ok) {
            throw new Error(`Lookup failed with status ${resp.status}`)
        }

        const data = await resp.json()
        if (Array.isArray(data) && data.length > 0) {
            const [first] = data
            mapCenter.value = [parseFloat(first.lat), parseFloat(first.lon)]
        } else {
            searchError.value = 'No results found for that query.'
        }
    } catch (error) {
        console.error('Search failed', error)
        searchError.value = 'Unable to complete the search right now. Please try again later.'
    }
}

function downloadCsv() {
    window.location.href = `${API_BASE_URL}/export`
}
</script>
