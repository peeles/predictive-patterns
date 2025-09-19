<template>
    <section class="space-y-4" aria-labelledby="preview-heading">
        <header>
            <h3 id="preview-heading" class="text-base font-semibold text-slate-900">Preview</h3>
            <p class="mt-1 text-sm text-slate-600">
                Confirm the parsed rows and schema alignment before submitting the dataset for ingestion.
            </p>
        </header>

        <article class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <h4 class="text-sm font-semibold text-slate-900">Schema summary</h4>
            <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <template v-for="(value, key) in datasetStore.schemaMapping" :key="key">
                    <div class="flex justify-between gap-4 rounded-md bg-white px-3 py-2 shadow-sm">
                        <dt class="font-medium capitalize">{{ key }}</dt>
                        <dd class="text-slate-600">{{ value || 'Auto' }}</dd>
                    </div>
                </template>
            </dl>
        </article>

        <div v-if="datasetStore.previewRows.length" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th v-for="column in columns" :key="column" class="px-3 py-2">{{ column }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, rowIndex) in datasetStore.previewRows" :key="rowIndex" class="odd:bg-white even:bg-slate-50">
                        <td v-for="column in columns" :key="`${rowIndex}-${column}`" class="px-3 py-2 text-slate-700">
                            {{ row[column] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No preview rows available. Upload a dataset file to inspect its contents.
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
</script>
