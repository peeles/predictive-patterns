<template>
    <Teleport
        to="body"
        v-if="open"
    >
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 px-4 py-8"
            role="dialog"
            aria-modal="true"
        >
            <div class="w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-stone-200 px-6 py-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Forecast workspace</p>
                        <h2 id="predict-form-heading" class="text-2xl font-semibold text-stone-900">Generate a prediction</h2>
                    </div>
                </header>
                <PredictForm
                    :disabled="predictionStore.loading"
                    :initial-filters="predictionStore.lastFilters"
                    :errors="formErrors"
                    @submit="handleSubmit"
                />
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import PredictForm from "./PredictForm.vue";
import {usePredictionStore} from "../../stores/prediction.js";
import {ref} from "vue";

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'generated'])

const predictionStore = usePredictionStore()

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
