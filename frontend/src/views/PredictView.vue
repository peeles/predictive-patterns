<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between">
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                    Forecast Workspace
                </p>
                <h1 class="text-2xl font-semibold text-stone-900">
                    Predictive Mapping
                </h1>
                <p class="text-sm text-stone-600">
                    Configure the forecast horizon and geography to build a fresh prediction using the latest ingested data.
                </p>
            </div>
            <button
                v-if="isAdmin"
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                type="button"
                @click="openWizard"
            >
                Launch predict wizard
            </button>
        </header>

        <div class="space-y-4">
            <nav
                aria-label="Prediction workspace views"
                class="flex flex-wrap items-center gap-2 border-b border-stone-200"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                    @click="selectTab(tab.id)"
                    :class="[
                        'relative -mb-px border-b-2 px-4 py-2 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500',
                        activeTab === tab.id
                            ? 'border-blue-600 text-blue-600'
                            : 'border-transparent text-stone-500 hover:text-stone-700',
                    ]"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <div v-show="activeTab === 'map'" class="space-y-6" role="region" aria-live="polite">
                <div class="relative isolate">
                    <Suspense>
                        <template #default>
                            <MapView
                                :center="mapCenter"
                                :points="predictionStore.heatmapPoints"
                                :radius-km="predictionStore.lastFilters.radiusKm"
                                :tile-options="heatmapTileOptions"
                            />
                        </template>
                        <template #fallback>
                            <div class="h-full min-h-[24rem] rounded-xl border border-stone-200/80 bg-white p-6 shadow-sm shadow-stone-200/70">
                                <p class="text-sm text-stone-500">Loading map…</p>
                            </div>
                        </template>
                    </Suspense>
                </div>

                <PredictionResult
                    v-if="predictionStore.hasPrediction"
                    :features="predictionStore.featureBreakdown"
                    :radius="predictionStore.lastFilters.radiusKm"
                    :summary="predictionSummary"
                />
            </div>

            <div v-show="activeTab === 'archive'" class="space-y-6" role="region">
                <PredictionHistory />
            </div>
        </div>

        <PredictGenerateModal
            v-if="isAdmin"
            :open="wizardOpen"
            @close="wizardOpen = false"
            @generated="wizardOpen = false"
        />
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { usePredictionStore } from '../stores/prediction'
import PredictionResult from '../components/predict/PredictionResult.vue'
import PredictionHistory from '../components/predict/PredictionHistory.vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '../stores/auth.js'
import PredictGenerateModal from '../components/predict/PredictGenerateModal.vue'

const MapView = defineAsyncComponent(() => import('../components/map/MapView.vue'))

const predictionStore = usePredictionStore()
const authStore = useAuthStore()
const { isAdmin } = storeToRefs(authStore)

const wizardOpen = ref(false)
const tabs = [
    { id: 'map', label: 'Map view' },
    { id: 'archive', label: 'Prediction archive' },
]
const activeTab = ref(tabs[0].id)

const mapCenter = computed(() => predictionStore.currentPrediction?.filters?.center ?? predictionStore.lastFilters.center)

const heatmapTileOptions = computed(() => {
    const options = {}
    const tsStart = predictionStore.lastFilters.timestamp
    if (tsStart) {
        options.tsStart = tsStart
    }
    const horizon = Number(predictionStore.lastFilters.horizon)
    if (Number.isFinite(horizon) && horizon >= 0) {
        options.horizon = horizon
    }
    return options
})

const predictionSummary = computed(() => ({
    generatedAt: predictionStore.currentPrediction?.generatedAt,
    horizonHours: predictionStore.summary?.horizonHours ?? predictionStore.lastFilters.horizon,
    riskScore: predictionStore.summary?.riskScore ?? 0,
    confidence: predictionStore.summary?.confidence ?? 'Unknown',
}))

function openWizard() {
    wizardOpen.value = true;
}

function selectTab(id) {
    activeTab.value = id
}
</script>
