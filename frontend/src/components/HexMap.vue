<template>
    <section class="flex-1 relative h-full">
        <div ref="mapEl" class="w-full h-full"></div>

        <!-- Controls -->
        <div class="absolute bottom-3 left-3 bg-white rounded shadow-2xl p-4 z-[1000] text-xs ring-1 ring-black/20">
            <fieldset class="mb-2">
                <label class="block text-xs mb-1">H3 Resolution: {{ resolution }}</label>
                <input v-model.number="resolution" class="w-32" type="range" min="5" max="9" step="1" />
            </fieldset>
        </div>
    </section>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
import L from 'leaflet'
import axios from 'axios'
import * as h3 from 'h3-js'
import * as d3 from 'd3'

const DEBUG = true

const props = defineProps({
    windowStart: String,
    windowEnd: String,
    center: { type: Array, default: () => [53.4084, -2.9916] },
    crimeType: { type: String, default: 'all' },
    district: { type: String, default: '' },
    customLayer: { type: String, default: '' },
})

let map
let layerGroup
let legendCtl = null

const mapEl = ref(null)
const resolution = ref(8)
const apiBase = import.meta.env.VITE_API_BASE || 'http://localhost:8000'

// --- request cancellation & debounce ---
let pendingController = null
let debounceTimer = null
const DEBOUNCE_MS = 200

function cancelPending() {
    if (pendingController) {
        try { pendingController.abort() } catch (_) {}
    }
    pendingController = null
}
function debouncedRender() {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => { renderPredictions() }, DEBOUNCE_MS)
}

// --- H3 helpers ---
function polygonFromH3(cell) {
    try {
        // Returns [[lat,lng], ...] which Leaflet accepts directly
        return h3.cellToBoundary(cell)
    } catch {
        return null
    }
}

/** Try multiple shapes so polygonToCells always returns something across h3-js versions */
function computeViewportCells(res) {
    const b = map.getBounds()
    if (!b || !b.isValid()) return []

    // lat,lng ring (Leaflet-style)
    const ringLatLng = [
        [b.getSouth(), b.getWest()],
        [b.getSouth(), b.getEast()],
        [b.getNorth(), b.getEast()],
        [b.getNorth(), b.getWest()],
        [b.getSouth(), b.getWest()],
    ]
    // GeoJSON ring (lng,lat)
    const ringGeo = ringLatLng.map(([lat, lng]) => [lng, lat])

    let areas = []
    try {
        areas = h3.polygonToCells(ringLatLng, res)
        if (areas.length) { DEBUG && console.debug('[H3] polygonToCells lat/lng ring:', areas.length); return areas }
    } catch (_) {}

    try {
        areas = h3.polygonToCells([ringLatLng], res)
        if (areas.length) { DEBUG && console.debug('[H3] polygonToCells loop-list (lat/lng):', areas.length); return areas }
    } catch (_) {}

    try {
        areas = h3.polygonToCells(ringGeo, res, { isGeoJson: true })
        if (areas.length) { DEBUG && console.debug('[H3] polygonToCells GeoJSON ring:', areas.length); return areas }
    } catch (_) {}

    try {
        areas = h3.polygonToCells([ringGeo], res, { isGeoJson: true })
        if (areas.length) { DEBUG && console.debug('[H3] polygonToCells GeoJSON loop-list:', areas.length); return areas }
    } catch (e) {
        console.error('H3 polygonToCells error:', e)
    }

    DEBUG && console.warn('[H3] No cells produced for viewport.')
    return []
}

// --- Quantized colour scale (buckets) ---
const BUCKETS = 6
const bucketColors = ['#2ECC71', '#7FD67F', '#C9E68D', '#F6D04D', '#F39C12', '#E74C3C']
let quant = d3.scaleQuantize().range(bucketColors)

function setQuantDomain(min, max) {
    if (!(Number.isFinite(min) && Number.isFinite(max))) {
        quant.domain([0, 1]); return
    }
    if (min === max) {
        const eps = Math.max(1e-6, Math.abs(min) * 0.01)
        quant.domain([min - eps, max + eps])
    } else {
        quant.domain([min, max])
    }
}
function colorForRisk(v) {
    const x = Number.isFinite(v) ? v : 0
    return quant(x)
}

// --- Legend ---
function removeLegend() {
    if (legendCtl) { legendCtl.remove(); legendCtl = null }
}
function addLegend() {
    removeLegend()
    const thresholds = quant.thresholds ? quant.thresholds() : d3.range(BUCKETS - 1)
    const Legend = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd() {
            const div = L.DomUtil.create('div', 'leaflet-control legend')
            Object.assign(div.style, {
                background: 'rgba(255,255,255,0.95)',
                padding: '8px 10px',
                borderRadius: '10px',
                boxShadow: '0 1px 4px rgba(0,0,0,.15)',
                font: '12px/1.3 system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
                maxWidth: '240px'
            })
            const title = document.createElement('div')
            title.style.fontWeight = '700'
            title.style.marginBottom = '6px'
            title.textContent = 'Risk (buckets)'
            div.appendChild(title)

            const fmt = d3.format('.3f')
            const [d0, d1] = quant.domain()
            const edges = [d0, ...thresholds, d1]
            for (let i = 0; i < edges.length - 1; i++) {
                const row = document.createElement('div')
                row.style.display = 'flex'
                row.style.alignItems = 'center'
                row.style.gap = '8px'
                row.style.marginTop = i === 0 ? '0' : '4px'

                const swatch = document.createElement('span')
                Object.assign(swatch.style, {
                    display: 'inline-block',
                    width: '18px',
                    height: '12px',
                    border: '1px solid rgba(0,0,0,.15)',
                    background: bucketColors[i]
                })
                const label = document.createElement('span')
                label.textContent = `${fmt(edges[i])} – ${fmt(edges[i+1])}`

                row.appendChild(swatch)
                row.appendChild(label)
                div.appendChild(row)
            }
            return div
        }
    })
    legendCtl = new Legend()
    legendCtl.addTo(map)
}

// --- API ---
async function fetchPredictions(startISO, endISO, crimeType, district, customLayer) {
    if (!map) return { predictions: [], areas: [] }

    const res = Math.max(5, Math.min(9, Number(resolution.value) || 8))
    let areas = computeViewportCells(res)
    if (areas.length > 1200) areas = areas.slice(0, 1200) // allow more for diagnosis

    cancelPending()
    pendingController = new AbortController()

    try {
        const resp = await axios.post(
            `${apiBase}/predict`,
            {
                areas,
                window_start: startISO,
                window_end: endISO,
                crime_type: crimeType,
                district,
                layer: customLayer,
            },
            { signal: pendingController.signal }
        )
        const predictions = resp.data?.predictions || []
        DEBUG && console.debug('[API] areas:', areas.length, 'predictions:', predictions.length)
        return { predictions, areas }
    } catch (e) {
        if (axios.isCancel?.(e) || e.name === 'CanceledError' || e.name === 'AbortError') {
            return { predictions: [], areas }
        }
        console.error('Error fetching predictions:', e)
        return { predictions: [], areas }
    } finally {
        pendingController = null
    }
}

// --- render ---
async function renderPredictions() {
    if (!map) return

    const { predictions, areas } = await fetchPredictions(
        props.windowStart,
        props.windowEnd,
        props.crimeType,
        props.district,
        props.customLayer
    )

    layerGroup.clearLayers()

    // If no predictions, draw the viewport grid as grey wireframe so you SEE something.
    if (!predictions.length) {
        DEBUG && console.warn('[Render] No predictions returned; drawing viewport grid wireframe.')
        for (const id of areas) {
            const ring = polygonFromH3(id)
            if (!ring) continue
            L.polygon(ring, {
                pane: 'hexPane',
                weight: 1,
                color: '#888',
                fillOpacity: 0,
            }).addTo(layerGroup)
        }
        removeLegend()
        return
    }

    // Domain from whatever the API returned (including zeros so legend reflects it)
    const risks = predictions.map(p => Number(p.risk)).filter(Number.isFinite)
    const min = d3.min(risks)
    const max = d3.max(risks)
    setQuantDomain(min, max)
    removeLegend()
    addLegend()

    for (const p of predictions) {
        const ring = polygonFromH3(p.area_id)
        if (!ring) continue

        const risk = Number(p.risk)
        const layer = L.polygon(ring, {
            pane: 'hexPane',
            weight: 1,
            color: '#333',
            fillColor: colorForRisk(risk),
            fillOpacity: 0.55,
        })

        const riskText = Number.isFinite(risk) ? risk.toFixed(3) : 'n/a'
        layer.bindPopup(`Cell: ${p.area_id}<br/>Risk: ${riskText}<br/>Crime: ${props.crimeType}`)
        layer.bindTooltip(`Risk: ${riskText}`, { sticky: true })
        layerGroup.addLayer(layer)
    }
}

// --- lifecycle ---
onMounted(async () => {
    map = L.map(mapEl.value).setView(props.center, 12)

    const light = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 18 })
    const dark  = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',  { maxZoom: 18 })
    light.addTo(map)
    L.control.layers({ Light: light, Dark: dark }).addTo(map)

    map.createPane('hexPane')
    map.getPane('hexPane').style.zIndex = 500

    layerGroup = L.layerGroup().addTo(map)

    map.on('moveend', debouncedRender)
    map.on('zoomend', debouncedRender)

    await renderPredictions()
})

onBeforeUnmount(() => {
    cancelPending()
    clearTimeout(debounceTimer)
    removeLegend()
    if (map) {
        map.off('moveend', debouncedRender)
        map.off('zoomend', debouncedRender)
        map.remove()
    }
})

// --- watchers ---
watch(
    () => [props.windowStart, props.windowEnd, props.crimeType, props.district, props.customLayer],
    () => debouncedRender()
)

watch(resolution, () => {
    resolution.value = Math.max(5, Math.min(9, Number(resolution.value) || 8))
    debouncedRender()
})

watch(() => props.center, (val) => {
    if (map && val) map.setView(val, 14)
})
</script>
