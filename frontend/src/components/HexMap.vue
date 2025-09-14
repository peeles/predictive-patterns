<template>
  <section class="relative h-full">
    <div ref="mapEl" class="w-full h-full"></div>

    <div class="absolute bottom-3 left-3 z-[1000] bg-white/90 backdrop-blur rounded-lg p-3 text-xs shadow">
      <label class="block mb-1">H3 Resolution: {{ resolution }}</label>
      <input type="range" min="6" max="8" v-model.number="resolution" class="w-48" @change="refresh" />
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import L from 'leaflet'
import * as h3 from 'h3-js'

const mapEl = ref<HTMLDivElement|null>(null)
const map = ref<L.Map|null>(null)
const layer = ref<L.GeoJSON|null>(null)
const resolution = ref(7)

const API = import.meta.env.VITE_API_URL ?? 'http://localhost:8080/api'

function bboxFromMap(m: L.Map) {
  const b = m.getBounds()
  return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`
}

async function refresh() {
  if (!map.value) return
  const bbox = bboxFromMap(map.value)
  const url  = `${API}/hexes?bbox=${bbox}&resolution=${resolution.value}`
  const res  = await fetch(url)
  const data = await res.json() as { cells: Array<{h3:string,count:number,categories:Record<string,number>}> }
  drawHexes(data.cells)
}

function drawHexes(cells: Array<{h3:string,count:number,categories:Record<string,number>}>) {
  if (!map.value) return
  if (layer.value) { layer.value.remove(); layer.value = null }
  const fc = {
    type: 'FeatureCollection',
    features: cells.map(c => ({
      type: 'Feature',
      properties: { count: c.count, h3: c.h3 },
      geometry: {
        type: 'Polygon',
        coordinates: [ h3.h3ToGeoBoundary(c.h3,true) ]
      }
    }))
  } as any
  layer.value = L.geoJSON(fc, {
    style: (f) => {
      const count = (f.properties?.count ?? 0) as number
      const t = Math.max(0, Math.min(1, count / 10))
      const r = Math.round(255 * t)
      const g = Math.round(200 * (1 - t))
      return { color: `rgb(${r},${g},80)`, weight: 1, fillOpacity: 0.35 }
    }
  }).addTo(map.value!)
}

onMounted(() => {
  map.value = L.map(mapEl.value!, { preferCanvas: true }).setView([53.394, -3.03], 13)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map.value)
  map.value.on('moveend', refresh)
  refresh()
})
</script>

<style>
@import "leaflet/dist/leaflet.css";
</style>
