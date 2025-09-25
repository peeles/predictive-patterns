<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between">
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                    Forecast Workspace
                </p>
                <h1 class="text-2xl font-semibold text-slate-900">
                    Predictive Mapping
                </h1>
                <p class="text-sm text-slate-600">
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

        <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)] 2xl:grid-cols-[minmax(0,480px)_minmax(0,1fr)]">
            <section class="rounded-xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70" aria-labelledby="predict-form-heading">
                <header class="mb-6 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Forecast workspace</p>
                    <h2 id="predict-form-heading" class="text-2xl font-semibold text-slate-900">Generate a prediction</h2>
                </header>
                <PredictForm
                    :disabled="predictionStore.loading"
                    :initial-filters="predictionStore.lastFilters"
                    :errors="formErrors"
                    @submit="handleSubmit"
                />
            </section>
            <div class="relative isolate space-y-6">
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
                        <div class="h-full min-h-[24rem] rounded-xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70">
                            <p class="text-sm text-slate-500">Loading map…</p>
                        </div>
                    </template>
                </Suspense>

                <PredictionResult
                    v-if="predictionStore.hasPrediction"
                    :features="predictionStore.featureBreakdown"
                    :radius="predictionStore.lastFilters.radiusKm"
                    :summary="predictionSummary"
                />

                <PredictionHistory />
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { usePredictionStore } from '../stores/prediction'
import PredictForm from '../components/predict/PredictForm.vue'
import PredictionResult from '../components/predict/PredictionResult.vue'
import PredictionHistory from '../components/predict/PredictionHistory.vue'
import {storeToRefs} from "pinia";
import {useAuthStore} from "../stores/auth.js";

const MapView = defineAsyncComponent(() => import('../components/map/MapView.vue'))

const predictionStore = usePredictionStore()
const authStore = useAuthStore()
const { isAdmin } = storeToRefs(authStore)

const wizardOpen = ref(false)

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

const formErrors = ref({})

async function handleSubmit(payload) {
    formErrors.value = {}
    try {
        await predictionStore.submitPrediction(payload)
    } catch (error) {
        if (error.validationErrors) {
            formErrors.value = error.validationErrors
        }
    }
}
</script>
