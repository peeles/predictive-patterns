<template>
    <section class="space-y-4" aria-labelledby="upload-heading">
        <header>
            <h3 id="upload-heading" class="text-base font-semibold text-slate-900">Upload dataset</h3>
            <p class="mt-1 text-sm text-slate-600">Supported formats: CSV or JSON up to 15MB.</p>
        </header>
        <label
            class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center transition hover:border-slate-400 focus-within:border-blue-500 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-blue-500"
        >
            <input class="sr-only" type="file" accept=".csv,.json" @change="onFileChange" />
            <svg aria-hidden="true" class="h-10 w-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M12 16V4m0 0l-3.5 3.5M12 4l3.5 3.5" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" />
                <path d="M6 16v2a2 2 0 002 2h8a2 2 0 002-2v-2" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" />
            </svg>
            <span class="mt-3 text-sm font-medium text-slate-700">Select file</span>
            <span class="mt-1 text-xs text-slate-500">Drop a file or browse from your computer.</span>
        </label>
        <p v-if="datasetStore.uploadFile" class="text-sm text-slate-600">
            Selected file: <strong>{{ datasetStore.uploadFile.name }}</strong>
        </p>
        <ul v-if="datasetStore.validationErrors.length" class="space-y-2" role="alert">
            <li
                v-for="error in datasetStore.validationErrors"
                :key="error"
                class="rounded-md border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700"
            >
                {{ error }}
            </li>
        </ul>
    </section>
</template>

<script setup>
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

async function onFileChange(event) {
    const [file] = event.target.files
    if (!datasetStore.validateFile(file)) {
        return
    }
    await datasetStore.parsePreview(file)
}
</script>
