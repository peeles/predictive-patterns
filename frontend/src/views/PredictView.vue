<template>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,360px)_1fr]">
        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="predict-form-heading">
            <header class="mb-4">
                <h1 id="predict-form-heading" class="text-xl font-semibold text-slate-900">Generate a prediction</h1>
                <p class="mt-1 text-sm text-slate-600">
                    Configure the forecast horizon and geography to build a fresh prediction using the latest ingested data.
                </p>
            </header>
            <PredictForm :disabled="predictionStore.loading" :initial-filters="predictionStore.lastFilters" @submit="handleSubmit" />
        </section>
        <div class="space-y-6">
            <Suspense>
                <template #default>
                    <MapView
                        :center="mapCenter"
                        :points="predictionStore.heatmapPoints"
                        :radius-km="predictionStore.lastFilters.radiusKm"
                    />
                </template>
                <template #fallback>
                    <div class="h-full min-h-[24rem] rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
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
        </div>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { usePredictionStore } from '../stores/prediction'
import PredictForm from '../components/predict/PredictForm.vue'
import PredictionResult from '../components/predict/PredictionResult.vue'

const MapView = defineAsyncComponent(() => import('../components/map/MapView.vue'))

const predictionStore = usePredictionStore()

const mapCenter = computed(() => predictionStore.currentPrediction?.filters?.center ?? predictionStore.lastFilters.center)

const predictionSummary = computed(() => ({
    generatedAt: predictionStore.currentPrediction?.generatedAt,
    horizonHours: predictionStore.summary?.horizonHours ?? predictionStore.lastFilters.horizon,
    riskScore: predictionStore.summary?.riskScore ?? 0,
    confidence: predictionStore.summary?.confidence ?? 'Unknown',
}))

async function handleSubmit(payload) {
    await predictionStore.submitPrediction(payload)
}
</script>
