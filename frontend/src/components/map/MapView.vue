<template>
    <section aria-label="Prediction heatmap" class="flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/70">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 px-6 py-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Map view</h2>
                <p class="text-sm text-slate-500">Visualise predicted hotspots across the selected radius.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Map preferences">
                <button
                    class="rounded-xl border border-slate-200/80 px-4 py-2 text-sm font-medium text-slate-700 shadow-sm shadow-slate-200/60 transition hover:border-slate-300 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
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
            <div
                v-if="fallbackReason"
                class="absolute inset-0 flex items-center justify-center bg-slate-50 px-6 text-center text-sm text-slate-600"
            >
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
import apiClient from '../../services/apiClient'
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
    tileOptions: {
        type: Object,
        default: () => ({}),
    },
})

const mapStore = useMapStore()
const mapContainer = ref(null)
const mapInstance = shallowRef(null)
const tileLayer = shallowRef(null)
const heatLayer = shallowRef(null)
const radiusCircle = shallowRef(null)
const fallbackReason = ref('')
const heatmapOptions = ref(normalizeTileOptions(props.tileOptions ?? {}))
let leafletLib = null

const tileSources = {
    streets: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    satellite: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
}

function normalizeTileOptions(value = {}) {
    if (!value || typeof value !== 'object') {
        return {}
    }

    const normalized = {}

    const tsStart = value.tsStart ?? value.ts_start
    if (tsStart) {
        normalized.tsStart = tsStart
    }

    const tsEnd = value.tsEnd ?? value.ts_end
    if (tsEnd) {
        normalized.tsEnd = tsEnd
    }

    const horizonCandidate = value.horizonHours ?? value.horizon ?? value.horizon_hours
    const parsedHorizon = Number(horizonCandidate)
    if (Number.isFinite(parsedHorizon) && parsedHorizon >= 0) {
        normalized.horizon = Math.round(parsedHorizon)
    }

    return normalized
}

function buildTileParams(options = {}) {
    const params = {}
    if (options.tsStart) {
        params.ts_start = options.tsStart
    }
    if (options.tsEnd) {
        params.ts_end = options.tsEnd
    }
    if (Number.isFinite(options.horizon) && options.horizon > 0) {
        params.horizon = options.horizon
    }
    return params
}

function projectToTile(lng, lat, coords, tileSize) {
    const size = tileSize.x || 256
    const scale = size * Math.pow(2, coords.z)
    const sinLat = Math.min(Math.max(Math.sin((lat * Math.PI) / 180), -0.9999), 0.9999)
    const worldX = ((lng + 180) / 360) * scale
    const worldY = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
    const pixelX = worldX - coords.x * size
    const pixelY = worldY - coords.y * size
    return [pixelX, pixelY]
}

function colorForIntensity(intensity) {
    const clamped = Math.max(0, Math.min(1, intensity))
    const start = [37, 99, 235]
    const end = [14, 165, 233]
    const mix = (from, to) => Math.round(from + (to - from) * clamped)
    const alpha = Math.min(0.85, 0.25 + clamped * 0.55)
    return `rgba(${mix(start[0], end[0])}, ${mix(start[1], end[1])}, ${mix(start[2], end[2])}, ${alpha})`
}

function drawHeatmapTile(context, coords, size, data) {
    context.clearRect(0, 0, size.x, size.y)

    const cells = Array.isArray(data?.cells) ? data.cells : []
    if (!cells.length) {
        return
    }

    let maxCount = Number(data?.meta?.max_count ?? 0)
    if (!Number.isFinite(maxCount) || maxCount <= 0) {
        maxCount = cells.reduce((max, cell) => Math.max(max, Number(cell?.count ?? 0)), 0)
    }

    if (!maxCount) {
        return
    }

    context.imageSmoothingEnabled = true
    context.globalCompositeOperation = 'lighter'
    context.lineJoin = 'round'
    context.lineCap = 'round'

    cells.forEach((cell) => {
        const polygon = Array.isArray(cell?.polygon) ? cell.polygon : []
        if (polygon.length < 3) {
            return
        }

        const count = Number(cell.count ?? 0)
        if (!Number.isFinite(count) || count <= 0) {
            return
        }

        const path = polygon
            .map((vertex) => {
                const lng = typeof vertex?.lng === 'number' ? vertex.lng : Number(vertex?.[0])
                const lat = typeof vertex?.lat === 'number' ? vertex.lat : Number(vertex?.[1])
                if (!Number.isFinite(lng) || !Number.isFinite(lat)) {
                    return null
                }
                return projectToTile(lng, lat, coords, size)
            })
            .filter(Boolean)

        if (path.length < 3) {
            return
        }

        const intensity = Math.max(0, Math.min(1, count / maxCount))
        context.beginPath()
        path.forEach(([px, py], index) => {
            if (index === 0) {
                context.moveTo(px, py)
            } else {
                context.lineTo(px, py)
            }
        })
        context.closePath()
        context.fillStyle = colorForIntensity(intensity)
        context.fill()
        context.strokeStyle = `rgba(30, 64, 175, ${0.12 + intensity * 0.18})`
        context.lineWidth = 0.6
        context.stroke()
    })

    context.globalCompositeOperation = 'source-over'
}

function createHeatmapLayer() {
    const controllers = new Map()
    const layer = leafletLib.gridLayer({ tileSize: 256, updateWhenIdle: true, keepBuffer: 2 })

    const handleTileUnload = (event) => {
        const controller = controllers.get(event.tile)
        if (controller) {
            controller.abort()
            controllers.delete(event.tile)
        }
    }

    layer.on('tileunload', handleTileUnload)

    layer.createTile = function createTile(coords, done) {
        const size = this.getTileSize()
        const canvas = document.createElement('canvas')
        canvas.width = size.x
        canvas.height = size.y
        const context = canvas.getContext('2d')

        if (!context) {
            done(null, canvas)
            return canvas
        }

        const controller = new AbortController()
        controllers.set(canvas, controller)

        apiClient
            .get(`/heatmap/${coords.z}/${coords.x}/${coords.y}`, {
                params: buildTileParams(heatmapOptions.value),
                signal: controller.signal,
            })
            .then(({ data }) => {
                drawHeatmapTile(context, coords, size, data)
            })
            .catch((error) => {
                if (error?.code !== 'ERR_CANCELED') {
                    console.error('Failed to load heatmap tile', error)
                }
                context.clearRect(0, 0, size.x, size.y)
            })
            .finally(() => {
                controllers.delete(canvas)
                done(null, canvas)
            })

        return canvas
    }

    layer.cancelPending = () => {
        controllers.forEach((controller) => controller.abort())
        controllers.clear()
    }

    layer.dispose = () => {
        layer.off('tileunload', handleTileUnload)
        layer.cancelPending()
    }

    return layer
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

    if (!heatLayer.value) {
        heatLayer.value = createHeatmapLayer()
    }

    if (!mapStore.showHeatmap) {
        heatLayer.value.cancelPending?.()
        if (mapInstance.value.hasLayer(heatLayer.value)) {
            mapInstance.value.removeLayer(heatLayer.value)
        }
        return
    }

    heatLayer.value.setOpacity?.(mapStore.heatmapOpacity)

    if (!mapInstance.value.hasLayer(heatLayer.value)) {
        heatLayer.value.addTo(mapInstance.value)
    } else {
        heatLayer.value.redraw()
    }
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
    () => mapStore.selectedBaseLayer,
    () => updateBaseLayer()
)

watch(
    () => mapStore.showHeatmap,
    () => updateHeatmap()
)

watch(
    () => mapStore.heatmapOpacity,
    (opacity) => {
        if (heatLayer.value) {
            heatLayer.value.setOpacity?.(opacity)
        }
    }
)

watch(
    () => props.radiusKm,
    () => updateRadiusCircle()
)

watch(
    () => props.tileOptions,
    (next) => {
        heatmapOptions.value = normalizeTileOptions(next ?? {})
        if (heatLayer.value && mapInstance.value?.hasLayer(heatLayer.value)) {
            heatLayer.value.redraw()
        }
    },
    { deep: true }
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
    heatLayer.value?.dispose?.()
})
</script>
