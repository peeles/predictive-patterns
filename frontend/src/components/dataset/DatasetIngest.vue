<template>
    <Transition name="fade">
        <div v-if="modelValue" class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/60 p-4" @click.self="close">
            <FocusTrap>
                <div
                    aria-describedby="dataset-ingest-description"
                    aria-labelledby="dataset-ingest-title"
                    aria-modal="true"
                    class="relative max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl"
                    @keydown.esc.prevent="close"
                    role="dialog"
                >
                    <header class="flex items-center justify-between border-b border-stone-200 px-6 py-4">
                        <div>
                            <h2 id="dataset-ingest-title" class="text-lg font-semibold text-stone-900">Dataset ingest wizard</h2>
                            <p id="dataset-ingest-description" class="text-sm text-stone-600">
                                Validate your file, align schema headers, preview parsed rows, and submit for processing.
                            </p>
                        </div>
                        <button
                            class="rounded-full p-2 text-stone-500 transition hover:bg-stone-100 hover:text-stone-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            type="button"
                            @click="close"
                        >
                            <span class="sr-only">Close wizard</span>
                            <svg aria-hidden="true" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                            </svg>
                        </button>
                    </header>

                    <nav aria-label="Wizard steps" class="border-b border-stone-200 bg-stone-50">
                        <ol class="flex divide-x divide-stone-200 text-sm">
                            <li
                                v-for="stepLabel in steps"
                                :key="stepLabel.id"
                                :aria-current="datasetStore.step === stepLabel.id ? 'step' : undefined"
                                class="flex-1 px-4 py-3"
                            >
                                <span
                                    :class="[
                                        'font-medium',
                                        datasetStore.step === stepLabel.id
                                            ? 'text-blue-600'
                                            : 'text-stone-500',
                                    ]"
                                >
                                    {{ stepLabel.label }}
                                </span>
                            </li>
                        </ol>
                    </nav>

                    <section class="max-h-[60vh] overflow-y-auto px-6 py-6 text-sm text-stone-700">
                        <component :is="activeStep" />
                    </section>

                    <footer class="flex items-center justify-between gap-3 border-t border-stone-200 px-6 py-4">
                        <button
                            class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            type="button"
                            @click="goBack"
                            :disabled="datasetStore.step === 1"
                        >
                            Back
                        </button>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="datasetStore.step < steps.length"
                                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                type="button"
                                :disabled="!canContinue"
                                @click="goNext"
                            >
                                Continue
                            </button>
                            <button
                                v-else
                                :disabled="datasetStore.submitting || !datasetStore.canSubmit"
                                class="inline-flex items-center justify-center gap-2 rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                type="button"
                                @click="submit"
                            >
                                <svg
                                    v-if="datasetStore.submitting"
                                    aria-hidden="true"
                                    class="h-4 w-4 animate-spin"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"></path>
                                </svg>
                                <span>{{ datasetStore.submitting ? 'Submitting…' : 'Submit dataset' }}</span>
                            </button>
                        </div>
                    </footer>
                </div>
            </FocusTrap>
        </div>
    </Transition>
</template>

<script setup>
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import FocusTrap from '../accessibility/FocusTrap.vue'
import { useDatasetStore } from '../../stores/dataset'
import UploadStep from './steps/UploadStep.vue'
import SchemaStep from './steps/SchemaStep.vue'
import PreviewStep from './steps/PreviewStep.vue'

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue', 'submitted'])

const datasetStore = useDatasetStore()
const { step } = storeToRefs(datasetStore)

const steps = [
    { id: 1, label: 'Upload' },
    { id: 2, label: 'Schema mapping' },
    { id: 3, label: 'Preview & submit' },
]

const stepComponents = {
    1: UploadStep,
    2: SchemaStep,
    3: PreviewStep,
}

const activeStep = computed(() => stepComponents[step.value])

const canContinue = computed(() => {
    if (datasetStore.step === 1) {
        return datasetStore.hasValidFile
    }
    if (datasetStore.step === 2) {
        return datasetStore.mappedFields >= 3
    }
    return true
})

function goNext() {
    if (datasetStore.step < steps.length) {
        datasetStore.setStep(datasetStore.step + 1)
    }
}

function goBack() {
    if (datasetStore.step > 1) {
        datasetStore.setStep(datasetStore.step - 1)
    }
}

function close() {
    emit('update:modelValue', false)
    datasetStore.reset()
}

async function submit() {
    if (!datasetStore.canSubmit) {
        return
    }
    const result = await datasetStore.submitIngestion({ submittedAt: new Date().toISOString() })
    if (result) {
        emit('submitted', result)
        close()
    }
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
