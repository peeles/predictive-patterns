<template>
    <form
        aria-describedby="prediction-form-caption"
        class="flex flex-col gap-6"
        novalidate
        @submit.prevent="onSubmit"
    >
        <p id="prediction-form-caption" class="text-sm text-slate-600">
            Select a location and observation window to generate a forecast. Fields marked with an asterisk are required.
        </p>

        <fieldset class="flex flex-col gap-2">
            <legend class="text-sm font-medium text-slate-900">Location*</legend>
            <label class="sr-only" for="location-search">Search for a place or postcode</label>
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <input
                        id="location-search"
                        v-model.trim="locationQuery"
                        :aria-busy="isSearching"
                        :disabled="disabled"
                        autocomplete="off"
                        class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        name="location"
                        placeholder="Search for a city, neighbourhood, or postcode"
                        type="search"
                        @keyup.enter.prevent="search"
                    />
                    <button
                        class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        @click="search"
                    >
                        {{ isSearching ? 'Searching…' : 'Search' }}
                    </button>
                </div>
                <p v-if="searchError" class="text-sm text-rose-600">{{ searchError }}</p>
                <ul
                    v-if="searchResults.length"
                    class="max-h-48 overflow-y-auto rounded-md border border-slate-200 bg-white"
                    role="listbox"
                    tabindex="-1"
                >
                    <li
                        v-for="result in searchResults"
                        :key="result.place_id"
                        :aria-selected="selectedLocation && selectedLocation.label === result.display_name"
                        class="cursor-pointer border-b border-slate-100 px-3 py-2 text-sm text-slate-700 last:border-b-0 focus:outline-none focus-visible:bg-blue-50 focus-visible:text-blue-700 hover:bg-blue-50"
                        role="option"
                        tabindex="0"
                        @click="selectResult(result)"
                        @keydown.enter.prevent="selectResult(result)"
                    >
                        {{ result.display_name }}
                    </li>
                </ul>
            </div>
            <p v-if="selectedLocation" class="text-sm text-slate-600">
                Selected centre: <strong>{{ selectedLocation.label }}</strong>
            </p>
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <label class="flex flex-col gap-2 text-sm font-medium text-slate-900">
                Observation end*
                <input
                    v-model="timestamp"
                    :disabled="disabled"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    name="timestamp"
                    type="datetime-local"
                    required
                />
            </label>
            <label class="flex flex-col gap-2 text-sm font-medium text-slate-900">
                Forecast horizon (hours)
                <input
                    v-model.number="horizon"
                    :disabled="disabled"
                    aria-describedby="horizon-help"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    max="48"
                    min="1"
                    name="horizon"
                    step="1"
                    type="number"
                />
                <span id="horizon-help" class="text-xs font-normal text-slate-500">Predictions up to two days ahead.</span>
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-2 text-sm text-slate-900">
            <legend class="text-sm font-medium text-slate-900">Radius (km)</legend>
            <div class="flex items-center gap-3">
                <input
                    v-model.number="radius"
                    :disabled="disabled"
                    aria-valuemax="5"
                    aria-valuemin="0.5"
                    aria-valuenow="radius"
                    class="h-2 flex-1 cursor-pointer appearance-none rounded-full bg-slate-200"
                    max="5"
                    min="0.5"
                    step="0.5"
                    type="range"
                />
                <span class="w-16 text-right font-medium">{{ radius.toFixed(1) }} km</span>
            </div>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3">
            <button
                :disabled="disabled || !canSubmit"
                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-slate-400"
                type="submit"
            >
                <svg
                    v-if="disabled"
                    aria-hidden="true"
                    class="h-4 w-4 animate-spin"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"></path>
                </svg>
                <span>{{ disabled ? 'Generating…' : 'Generate prediction' }}</span>
            </button>
            <p class="text-sm text-slate-600">Submission is disabled while a request is in progress.</p>
        </div>
    </form>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { notifyError } from '../../utils/notifications'

const props = defineProps({
    initialFilters: {
        type: Object,
        default: () => ({
            center: { lat: 51.5074, lng: -0.1278, label: 'London' },
            timestamp: new Date().toISOString().slice(0, 16),
            horizon: 6,
            radiusKm: 1.5,
        }),
    },
    disabled: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['submit'])

const timestamp = ref(props.initialFilters.timestamp)
const horizon = ref(props.initialFilters.horizon)
const radius = ref(props.initialFilters.radiusKm)
const locationQuery = ref('')
const selectedLocation = ref(props.initialFilters.center)
const isSearching = ref(false)
const searchError = ref('')
const searchResults = ref([])

onMounted(() => {
    if (!selectedLocation.value?.label) {
        selectedLocation.value = {
            ...props.initialFilters.center,
            label: 'Selected location',
        }
    }
})

const canSubmit = computed(() => Boolean(timestamp.value && selectedLocation.value))

async function search() {
    if (!locationQuery.value) {
        searchError.value = 'Enter a location to search.'
        return
    }

    searchError.value = ''
    isSearching.value = true
    try {
        const response = await fetch(
            `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(locationQuery.value)}`,
            {
                headers: {
                    'Accept-Language': 'en',
                },
            }
        )
        if (!response.ok) {
            throw new Error(`Lookup failed (${response.status})`)
        }
        const results = await response.json()
        if (Array.isArray(results) && results.length > 0) {
            searchResults.value = results
        } else {
            searchResults.value = []
            searchError.value = 'No results found for that query.'
        }
    } catch (error) {
        notifyError(error, 'Location lookup failed. Please try again shortly.')
        searchError.value = 'Unable to complete the search right now.'
    } finally {
        isSearching.value = false
    }
}

function selectResult(result) {
    selectedLocation.value = {
        lat: Number(result.lat),
        lng: Number(result.lon),
        label: result.display_name,
    }
    searchResults.value = []
    locationQuery.value = result.display_name
}

function onSubmit() {
    if (!canSubmit.value || props.disabled) return

    const payload = {
        center: { lat: selectedLocation.value.lat, lng: selectedLocation.value.lng, label: selectedLocation.value.label },
        timestamp: timestamp.value,
        horizon: Number(horizon.value),
        radiusKm: Number(radius.value),
    }
    emit('submit', payload)
}
</script>
