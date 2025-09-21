<template>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)] 2xl:grid-cols-[minmax(0,480px)_minmax(0,1fr)]">
        <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70" aria-labelledby="predict-form-heading">
            <header class="mb-6 space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Forecast workspace</p>
                <h1 id="predict-form-heading" class="text-2xl font-semibold text-slate-900">Generate a prediction</h1>
                <p class="text-sm text-slate-600">
                    Configure the forecast horizon and geography to build a fresh prediction using the latest ingested data.
                </p>
            </header>
            <PredictForm
                :disabled="predictionStore.loading"
                :initial-filters="predictionStore.lastFilters"
                :errors="formErrors"
                @submit="handleSubmit"
            />
        </section>
        <div class="space-y-6">
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
                    <div class="h-full min-h-[24rem] rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70">
                        <p class="text-sm text-slate-500">Loading map…</p>
                    </div>
                </template>
            </Suspense>

            <div class="space-y-6 2xl:hidden">
                <PredictionResult
                    v-if="predictionStore.hasPrediction"
                    :features="predictionStore.featureBreakdown"
                    :radius="predictionStore.lastFilters.radiusKm"
                    :summary="predictionSummary"
                />
                <NLQConsole />
                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 text-sm shadow-sm shadow-slate-200/70">
                    <header class="mb-4">
                        <h2 class="text-base font-semibold text-slate-900">Recent configuration</h2>
                        <p class="text-xs text-slate-500">Your last submitted parameters are saved for quick iteration.</p>
                    </header>
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Location</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-900">{{ lastLocation }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Forecast horizon</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-900">{{ lastHorizon }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Radius</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-900">{{ lastRadius }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Last run</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-900">{{ lastRunTime }}</dd>
                        </div>
                    </dl>
                </section>
            </div>
            <PredictionResult
                v-if="predictionStore.hasPrediction"
                :features="predictionStore.featureBreakdown"
                :radius="predictionStore.lastFilters.radiusKm"
                :summary="predictionSummary"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { usePredictionStore } from '../stores/prediction'
import PredictForm from '../components/predict/PredictForm.vue'
import PredictionResult from '../components/predict/PredictionResult.vue'

const MapView = defineAsyncComponent(() => import('../components/map/MapView.vue'))

const predictionStore = usePredictionStore()

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
