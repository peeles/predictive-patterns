<template>
    <section aria-label="Prediction heatmap" class="flex h-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Map view</h2>
                <p class="text-sm text-slate-600">Visualise predicted hotspots across the selected radius.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Map preferences">
                <button
                    class="rounded-md border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    @click="toggleBase"
                >
                    Base: {{ mapStore.baseLayerLabel }}
                </button>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input
                        v-model="mapStore.showHeatmap"
                        class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        type="checkbox"
                    />
                    Show heatmap
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    Opacity
                    <input
                        v-model.number="mapStore.heatmapOpacity"
                        class="h-2 w-24 cursor-pointer appearance-none rounded-full bg-slate-200"
                        max="1"
                        min="0.2"
                        step="0.1"
                        type="range"
                        aria-valuemin="0.2"
                        aria-valuemax="1"
                        :aria-valuenow="mapStore.heatmapOpacity"
                    />
                </label>
            </div>
        </header>
        <div class="relative flex-1">
            <div v-if="fallbackReason" class="absolute inset-0 flex items-center justify-center bg-slate-50 px-6 text-center text-sm text-slate-600">
                {{ fallbackReason }}
            </div>
            <div
                v-else
                ref="mapContainer"
                aria-label="Heatmap of predicted hotspots"
                class="h-full w-full focus:outline-none"
                role="application"
                tabindex="0"
            ></div>
        </div>
    </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useMapStore } from '../../stores/map'

const props = defineProps({
    center: {
        type: Object,
        required: true,
    },
    points: {
        type: Array,
        default: () => [],
    },
    radiusKm: {
        type: Number,
        default: 1.5,
    },
})

const mapStore = useMapStore()
const mapContainer = ref(null)
const mapInstance = shallowRef(null)
const tileLayer = shallowRef(null)
const heatLayer = shallowRef(null)
const radiusCircle = shallowRef(null)
const fallbackReason = ref('')
let leafletLib = null

const tileSources = {
    streets: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    satellite: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
}

async function ensureLeaflet() {
    if (leafletLib) return leafletLib
    try {
        const [{ default: L }] = await Promise.all([
            import('leaflet'),
            loadLeafletStyles(),
        ])
        leafletLib = L
        return L
    } catch (error) {
        console.error('Failed to load Leaflet', error)
        fallbackReason.value = 'Interactive map unavailable. Please ensure you are online and try again.'
        throw error
    }
}

function loadLeafletStyles() {
    return import('leaflet/dist/leaflet.css')
}

async function initMap() {
    try {
        const L = await ensureLeaflet()
        if (!mapContainer.value) return
        mapInstance.value = L.map(mapContainer.value, {
            center: [props.center.lat, props.center.lng],
            zoom: 13,
            preferCanvas: true,
        })
        updateBaseLayer()
        updateHeatmap()
        updateRadiusCircle()
    } catch (error) {
        fallbackReason.value = 'Unable to display the map in this browser.'
    }
}

function updateBaseLayer() {
    if (!leafletLib || !mapInstance.value) return
    if (tileLayer.value) {
        mapInstance.value.removeLayer(tileLayer.value)
    }
    tileLayer.value = leafletLib.tileLayer(tileSources[mapStore.selectedBaseLayer], {
        attribution: '&copy; OpenStreetMap contributors',
    })
    tileLayer.value.addTo(mapInstance.value)
}

function updateHeatmap() {
    if (!leafletLib || !mapInstance.value) return
    if (heatLayer.value) {
        heatLayer.value.clearLayers()
    } else {
        heatLayer.value = leafletLib.layerGroup()
    }

    if (!mapStore.showHeatmap) {
        if (mapInstance.value.hasLayer(heatLayer.value)) {
            mapInstance.value.removeLayer(heatLayer.value)
        }
        return
    }

    if (!mapInstance.value.hasLayer(heatLayer.value)) {
        heatLayer.value.addTo(mapInstance.value)
    }

    props.points.forEach((point) => {
        const intensity = point.intensity ?? 0.5
        const radiusPx = 10 + intensity * 30
        const circle = leafletLib.circleMarker([point.lat, point.lng], {
            radius: radiusPx,
            color: 'rgba(59, 130, 246, 0.6)',
            fillColor: 'rgba(37, 99, 235, 0.8)',
            fillOpacity: mapStore.heatmapOpacity,
            weight: 0,
        })
        circle.addTo(heatLayer.value)
    })
}

function updateRadiusCircle() {
    if (!leafletLib || !mapInstance.value) return
    if (radiusCircle.value) {
        mapInstance.value.removeLayer(radiusCircle.value)
    }
    radiusCircle.value = leafletLib.circle([props.center.lat, props.center.lng], {
        radius: props.radiusKm * 1000,
        color: '#1d4ed8',
        fill: false,
        weight: 1.5,
        dashArray: '4 4',
    })
    radiusCircle.value.addTo(mapInstance.value)
}

function toggleBase() {
    mapStore.toggleBaseLayer()
}

watch(
    () => ({ ...props.center }),
    (center) => {
        if (mapInstance.value) {
            mapInstance.value.setView([center.lat, center.lng], mapInstance.value.getZoom())
            updateRadiusCircle()
        }
    }
)

watch(
    () => props.points,
    () => {
        updateHeatmap()
    },
    { deep: true }
)

watch(
    () => mapStore.selectedBaseLayer,
    () => updateBaseLayer()
)

watch(
    () => mapStore.showHeatmap,
    () => updateHeatmap()
)

watch(
    () => mapStore.heatmapOpacity,
    () => updateHeatmap()
)

watch(
    () => props.radiusKm,
    () => updateRadiusCircle()
)

onMounted(() => {
    if (typeof window === 'undefined') {
        return
    }
    if (!('IntersectionObserver' in window)) {
        initMap()
        return
    }
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    initMap()
                    observer.disconnect()
                }
            })
        },
        { root: null, threshold: 0.1 }
    )
    if (mapContainer.value) {
        observer.observe(mapContainer.value)
    }
})

onBeforeUnmount(() => {
    if (mapInstance.value) {
        mapInstance.value.remove()
    }
})
</script>
