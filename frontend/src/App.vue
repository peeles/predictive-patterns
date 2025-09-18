<template>
    <div class="flex h-screen flex-row">
        <div class="flex w-96 flex-col gap-3 p-4">
            <h1 class="mb-2 text-xl font-medium">Pattern Prediction</h1>

            <fieldset>
                <label>Select a Date/Time:</label>
                <input
                    v-model="selectedDate"
                    class="relative w-full rounded border border-gray-300 px-1 py-1.5 mt-1
                       [&::-webkit-calendar-picker-indicator]:absolute
                       [&::-webkit-calendar-picker-indicator]:right-3
                       [&::-webkit-calendar-picker-indicator]:top-1/2
                       [&::-webkit-calendar-picker-indicator]:-translate-y-1/2
                       [&::-webkit-calendar-picker-indicator]:cursor-pointer"
                    type="datetime-local"
                />
            </fieldset>

            <fieldset class="mt-2 flex flex-row items-center space-x-2">
                <label>Interval:</label>
                <select
                    v-model="intervalType"
                    class="rounded border border-stone-300 px-2 py-1"
                >
                    <option value="hour">Hourly</option>
                    <option value="day">Daily</option>
                    <option value="week">Weekly</option>
                </select>
            </fieldset>

            <fieldset class="mt-2 flex flex-row items-center space-x-2">
                <label>Playback:</label>
                <input
                    v-model="playback"
                    :max="playbackMax"
                    :min="0"
                    type="range"
                />
                <span>{{ playbackLabel }}</span>
            </fieldset>

            <div class="mt-2">
                <label>Search Location:</label>
                <div class="flex mt-1">
                    <input
                        v-model="searchQuery"
                        class="flex-1 rounded border border-stone-300 px-2 py-1.5"
                        placeholder="Postcode or neighbourhood"
                        type="text"
                        @keyup.enter="searchLocation"
                    />
                    <button
                        class="ml-2 rounded border border-stone-300 px-2 py-1.5"
                        @click="searchLocation"
                    >Go
                    </button>
                </div>
            </div>

            <label class="mt-2">
                <span>Crime Type:</span>
                <select
                    v-model="crimeType"
                    class="mt-1 rounded border border-stone-300 px-2 py-1.5 w-full"
                >
                    <option value="all">All</option>
                    <option value="burglary">Burglary</option>
                    <option value="assault">Assault</option>
                    <option value="theft">Theft</option>
                </select>
            </label>

            <div class="mt-2">
                <label>District:</label>
                <input
                    v-model="district"
                    class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5"
                    placeholder="Enter district"
                    type="text"
                />
            </div>

            <div class="mt-2">
                <label>Custom Layer:</label>
                <input
                    v-model="customLayer"
                    class="mt-1 w-full rounded border border-stone-300 px-2 py-1.5"
                    placeholder="e.g. schools"
                    type="text"
                />
            </div>

            <NLQConsole/>

            <button
                class="mt-2 rounded border border-stone-300 px-2 py-1.5"
                @click="downloadCsv"
            >
                Export CSV
            </button>
        </div>
        <HexMap
            :center="mapCenter"
            :crime-type="crimeType"
            :custom-layer="customLayer"
            :district="district"
            :window-end="windowEnd"
            :window-start="windowStart"
        />
    </div>
</template>

<script setup>
import {ref, computed} from 'vue'
import HexMap from '../../../../Downloads/crime-pattern-prediction/frontend/src/components/HexMap.vue'
import NLQConsole from '../../../../Downloads/crime-pattern-prediction/frontend/src/components/NLQConsole.vue'

// Base date chosen by the user (ISO string without seconds for input binding)
const selectedDate = ref(new Date().toISOString().slice(0, 16))

// Interval granularity and playback offset
const intervalType = ref('hour')
const playback = ref(0)
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

// Search query and map center
const searchQuery = ref('')
const mapCenter = ref([53.4084, -2.9916])

async function searchLocation() {
    if (!searchQuery.value) {
        return
    }

    try {
        const resp = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchQuery.value)}`
        )
        const data = await resp.json()
        if (data && data[0]) {
            mapCenter.value = [parseFloat(data[0].lat), parseFloat(data[0].lon)]
        }
    } catch (e) {
        console.error('Search failed', e)
    }
}

// Crime type and additional filters
const crimeType = ref('all')
const district = ref('')
const customLayer = ref('')

// Derived start/end window in ISO format for the map component
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
    const st = new Date(windowEnd.value)
    if (intervalType.value === 'day') {
        st.setDate(st.getDate() - 1)
    } else if (intervalType.value === 'week') {
        st.setDate(st.getDate() - 7)
    } else {
        st.setHours(st.getHours() - 1)
    }
    return st.toISOString()
})

function downloadCsv() {
    const base = import.meta.env.VITE_API_URL || 'http://localhost:8000'
    window.location.href = `${base}/export`
}
</script>
