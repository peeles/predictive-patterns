<template>
    <section class="space-y-4" aria-labelledby="preview-heading">
        <header>
            <h3 id="preview-heading" class="text-base font-semibold text-stone-900">Preview</h3>
            <p class="mt-1 text-sm text-stone-600">
                Confirm the parsed rows and schema alignment before submitting the dataset for ingestion.
            </p>
        </header>

        <article class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <h4 class="text-sm font-semibold text-slate-900">Submission summary</h4>
            <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
                    <dd class="text-sm text-slate-800">{{ datasetStore.name }}</dd>
                </div>
                <div class="flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt>
                    <dd class="text-sm text-slate-800">{{ sourceLabel }}</dd>
                </div>
                <div v-if="datasetStore.description" class="sm:col-span-2 flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</dt>
                    <dd class="text-sm text-slate-700">{{ datasetStore.description }}</dd>
                </div>
                <div v-if="datasetStore.sourceType === 'url'" class="sm:col-span-2 flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Import URL</dt>
                    <dd class="text-sm text-slate-700 break-words">{{ datasetStore.sourceUri }}</dd>
                </div>
            </dl>
        </article>

        <article v-if="datasetStore.sourceType === 'file'" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <h4 class="text-sm font-semibold text-slate-900">Schema summary</h4>
            <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <template v-for="(value, key) in datasetStore.schemaMapping" :key="key">
                    <div class="flex justify-between gap-4 rounded-md bg-white px-3 py-2 shadow-sm">
                        <dt class="font-medium capitalize">{{ key }}</dt>
                        <dd class="text-stone-600">{{ value || 'Auto' }}</dd>
                    </div>
                </template>
            </dl>
        </article>

        <div v-if="datasetStore.sourceType === 'file' && datasetStore.previewRows.length" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th v-for="column in columns" :key="column" class="px-3 py-2">{{ column }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in datasetStore.previewRows" :key="rowIndex" class="odd:bg-white even:bg-stone-50">
                        <td v-for="column in columns" :key="`${rowIndex}-${column}`" class="px-3 py-2 text-stone-700">
                            {{ row[column] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else-if="datasetStore.sourceType === 'file'" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No preview rows available. Upload a dataset file to inspect its contents.
        </p>
        <p v-else class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Remote datasets are queued for download and validation after submission. Preview is unavailable for URL imports.
        </p>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

const columns = computed(() => {
    if (!datasetStore.previewRows.length) return []
    return Object.keys(datasetStore.previewRows[0])
})

const sourceLabel = computed(() => {
    if (datasetStore.sourceType === 'url') {
        return 'Import from URL'
    }
    if (datasetStore.uploadFiles.length > 1) {
        return `Upload (${datasetStore.uploadFiles.length} files)`
    }
    return datasetStore.uploadFiles.length === 1 ? datasetStore.uploadFiles[0].name : 'Upload files'
})
</script>
